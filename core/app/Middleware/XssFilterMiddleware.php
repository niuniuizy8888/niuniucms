<?php
namespace App\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * XSS 过滤中间件（渐进式迁移版）
 * 递归清洗 GET/POST/JSON 输入，防止 XSS 攻击
 * 
 * 功能：
 * 1. 递归清洗 Query/ParsedBody/JSON
 * 2. 默认对字符串进行 htmlspecialchars
 * 3. 指定字段（白名单）允许 HTML，并做轻量净化
 * 4. 移除 <script>/on* 事件/危险协议等
 * 5. 清洗结果写入 $_GET/$_POST 或 request attributes
 * 
 * 渐进式兼容：
 * - 不强制返回 ResponseInterface（兼容 echo/exit）
 * - 直接修改 $_GET/$_POST（现有代码无需改动）
 * - 可配置白名单字段
 */
class XssFilterMiddleware implements MiddlewareInterface
{
    /** @var array 配置选项 */
    private $options;
    
    /** @var array 允许 HTML 的字段（白名单） */
    private $allowHtmlFields = [];
    
    /** @var bool 是否替换原始数据 */
    private $replaceOriginal = true;
    
    /** @var int 最大递归深度 */
    private $maxDepth = 32;

    public function __construct(array $options = [])
    {
        $this->options = $options;
        $this->allowHtmlFields = $options['allow_html_fields'] ?? [];
        $this->replaceOriginal = $options['replace_original'] ?? true;
        $this->maxDepth = $options['max_depth'] ?? 32;
    }

    /**
     * PSR-15 环节：清洗 $_GET/$_POST 并（replace_original=true 时）替换回超全局；
     * JSON 请求体清洗结果写 $_SERVER['XSS_JSON']。$_SERVER['XSS_SKIP'] 为真（api.php 机器通道）直接透传。
     * 关键定位（F-07 / CLAUDE.md「安全红线·转义分层」）：**不回写 $_REQUEST**——param() 读 $_REQUEST 且自身默认
     * htmlspecialchars，回写会造成全输入面双重转义；主防线是 param() 默认转义 + 写侧 xn_html_safe + 输出侧转义，
     * 本中间件对 $_GET/$_POST 的替换属纵深冗余（直接消费方仅启动期 globals.php）。
     * @param \Psr\Http\Message\RequestInterface $request 传入请求（本管道实传 App\Request；实现签名未加类型，接口有声明）
     * @param RequestHandlerInterface            $handler 下一个处理器
     * @return mixed $handler->handle() 的管道结果（true=放行 / false=拦截，见 MiddlewareInterface 约定）
     */
    public function process($request, RequestHandlerInterface $handler)
    {
        // 检查是否跳过 XSS 过滤（路由属性）
        if (isset($_SERVER['XSS_SKIP']) && $_SERVER['XSS_SKIP']) {
            return $handler->handle($request);
        }
        
        // 清洗 GET 数据
        $cleanQuery = $this->sanitize($_GET);
        
        // 清洗 POST 数据
        $cleanBody = $this->sanitize($_POST);
        
        // 清洗 JSON 数据
        $cleanJson = null;
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if ($this->isJson($contentType)) {
            $raw = file_get_contents('php://input');
            if ($raw !== '') {
                $json = json_decode($raw, true);
                if (is_array($json)) {
                    $cleanJson = $this->sanitize($json);
                }
            }
        }
        
        // 替换原始数据（默认行为）
        if ($this->replaceOriginal) {
            $_GET = $cleanQuery;
            $_POST = $cleanBody;
            // 上线前安全审计修复（F-07）：撤销 对 $_REQUEST 的 array_replace 回写。
            // 原意图是让本中间件的清洗对 param() 生效（param() 主输入面读 $_REQUEST），但 param() 自身
            // 默认再做一次 htmlspecialchars ⇒ 应用全输入面被二次转义（线上实测搜索 a&b<c 输出
            // a&amp;amp;b&amp;lt;c&amp;gt;，types 筛选 href 出现 &amp;quot; 伪影），违反本项目
            // 「转义必须是数据流最后一步」红线，且破坏 SearchService/TypeService「原始读取→收尾转义」
            // 的修复前提、污染热词入库数据。真实防线 = param() 默认转义 + 写侧 xn_html_safe + 输出侧转义，
            // 三层经全量审计有效；本中间件保留对 $_GET/$_POST 的替换（直接消费方仅启动期 globals.php）。
            // JSON 数据写入 request attributes
            if ($cleanJson !== null) {
                $_SERVER['XSS_JSON'] = $cleanJson;
            }
        } else {
            // 写入 attributes（供后续中间件/控制器使用）
            $_SERVER['XSS_QUERY'] = $cleanQuery;
            $_SERVER['XSS_BODY'] = $cleanBody;
            if ($cleanJson !== null) {
                $_SERVER['XSS_JSON'] = $cleanJson;
            }
        }
        
        return $handler->handle($request);
    }
    
    /**
     * 递归清洗
     */
    private function sanitize($value, array $path = [], int $depth = 0)
    {
        if ($depth > $this->maxDepth) {
            return $value;
        }
        
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $seg = is_int($k) ? (string)$k : (string)$k;
                $out[$k] = $this->sanitize($v, array_merge($path, [$seg]), $depth + 1);
            }
            return $out;
        }
        
        if (is_string($value)) {
            $fieldPath = implode('.', $path);
            if ($this->isHtmlAllowedField($fieldPath)) {
                return $this->purifyHtml($value);
            }
            return $this->htmlEncode($value);
        }
        
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }
        
        if (is_object($value) && method_exists($value, '__toString')) {
            return $this->htmlEncode((string)$value);
        }
        
        return $value;
    }
    
    /**
     * 检查字段是否在 HTML 白名单
     */
    private function isHtmlAllowedField(string $fieldPath): bool
    {
        if ($fieldPath === '') return false;
        
        foreach ($this->allowHtmlFields as $allowed) {
            if ($allowed === $fieldPath) return true;
            // 尾段匹配（如允许 'content'，匹配 'article.content'）
            if (!str_contains($allowed, '.') && preg_match('#(^|\.)(?:' . preg_quote($allowed, '#') . ')$#', $fieldPath)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * HTML 编码
     */
    private function htmlEncode(string $s): string
    {
        $s = $this->stripControlChars($s);
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    
    /**
     * HTML 净化（白名单字段）
     */
    private function purifyHtml(string $html): string
    {
        $html = $this->stripControlChars($html);
        
        // 1. 结构初筛：保留安全标签
        $allowedTags = '<img><p><br><b><strong><i><em><u><ul><ol><li><a><span><div><blockquote><code><pre><h1><h2><h3><h4><h5><h6>'
            . '<hr><table><thead><tbody><tr><th><td>'
            . '<section><article><aside><nav><header><footer><figure><figcaption>';
        $html = strip_tags($html, $allowedTags);
        
        // 2. 属性级重组：审计每个标签的属性
        return preg_replace_callback('/<([a-z1-6]+)\s*([^>]*)>/i', function ($matches) {
            $tagName = strtolower($matches[1]);
            $attrStr = $matches[2];
            
            $attrs = $this->parseAttributes($attrStr);
            $cleanAttrs = [];
            $allowedList = $this->getAllowedAttributes($tagName);
            
            foreach ($attrs as $name => $val) {
                if (!in_array($name, $allowedList)) continue;
                
                $val = $this->sanitizeAttributeValue($tagName, $name, $val);
                if ($val !== null) {
                    $cleanAttrs[] = sprintf('%s="%s"', $name, htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                }
            }
            
            return '<' . $tagName . (empty($cleanAttrs) ? '' : ' ' . implode(' ', $cleanAttrs)) . '>';
        }, $html);
    }
    
    /**
     * 解析 HTML 属性
     */
    private function parseAttributes(string $attrStr): array
    {
        $attrs = [];
        $pattern = '/([a-z0-9_-]+)\s*=\s*(?:["\']([^"\']*)["\']|([^\s>]+))/i';
        if (preg_match_all($pattern, $attrStr, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $name = strtolower($m[1]);
                $value = $m[2] !== '' ? $m[2] : ($m[3] ?? '');
                $attrs[$name] = $value;
            }
        }
        return $attrs;
    }
    
    /**
     * 获取标签允许的属性和白名单
     */
    private function getAllowedAttributes(string $tagName): array
    {
        $common = ['class', 'id', 'style', 'title'];
        $map = [
            'a'   => ['href', 'target', 'rel'],
            'img' => ['src', 'alt', 'width', 'height'],
            'ol'  => ['type', 'start'],
            'ul'  => ['type'],
        ];
        return array_merge($common, $map[$tagName] ?? []);
    }
    
    /**
     * 属性值脱敏
     */
    private function sanitizeAttributeValue(string $tag, string $name, string $val): ?string
    {
        // 危险协议检查
        // 第五轮修复：主闸 xn_html_safe 允许 img src 的 data:image/*;base64 内联图
        // （security.php img_url 模式），此处维持同等放行；其余 data: 一律阻断
        // （data:image/svg+xml 可携脚本，data:text/html 已拦但 data: 全前缀一并收口）
        if ($name === 'src' && preg_match('#^data:image/(?:jpg|jpeg|gif|png);base64,[A-Za-z0-9+/=]+$#i', $val)) {
            return $val;
        }
        if (in_array($name, ['href', 'src', 'action'])) {
            if ($this->isDangerousProtocol($val)) {
                return '#';
            }
        }
        
        // 行内样式审计
        if ($name === 'style') {
            return $this->auditInlineStyle($val);
        }
        
        // id 安全校验
        if ($name === 'id') {
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9\-_:.]*\z/', $val)) {
                return null;
            }
            $reserved = ['location', 'closed', 'frames', 'length', 'name', 'opener', 'status'];
            if (in_array(strtolower($val), $reserved)) {
                return null;
            }
        }
        
        return $val;
    }
    
    /**
     * 危险协议检查
     */
    private function isDangerousProtocol(string $val): bool
    {
        // 还原 HTML 实体
        $normalized = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // 移除控制字符
        $normalized = preg_replace('/[\s\x00-\x1F\x7F-\x9F\xAD]/u', '', $normalized);
        $normalized = strtolower($normalized);
        
        // 黑名单协议（第五轮修复：data:text/html 扩为 data: 全前缀——
        // data:image/svg+xml 等可携脚本协议一并收口）
        $blackPage = ['javascript:', 'data:', 'vbscript:', 'mocha:', 'livescript:'];
        foreach ($blackPage as $p) {
            if (str_starts_with($normalized, $p)) return true;
        }
        
        return false;
    }
    
    /**
     * 行内样式审计
     */
    private function auditInlineStyle(string $style): ?string
    {
        $normalized = strtolower($style);
        $blackKeywords = ['expression', 'javascript', 'behavior', 'vmlframe', '-moz-binding', '@import'];
        
        foreach ($blackKeywords as $word) {
            if (str_contains($normalized, $word)) return null;
        }
        
        // url() 检查（第五轮修复：原实现仅对整段 style 做前缀式协议检查，
        // url(javascript:...) 形态可穿透。改为提取 url(...) 载荷逐一校验：仅放行 http/https
        // （对齐主闸 xn_html_safe 的 background url(https?://) 白名单口径）；javascript:/data:/
        // vbscript:/协议相对/相对路径一律剔除；形如 url( 但无法完整提取（嵌套括号等）时
        // fail-closed 拒绝）
        if (str_contains($normalized, 'url')) {
            $n = preg_match_all('#url\s*\(\s*([\'"]?)(.*?)\1\s*\)#is', $style, $m);
            if (!$n) return null;
            foreach ($m[2] as $u) {
                $u = trim($u);
                if ('' === $u || !preg_match('#^https?://#i', $u)) return null;
            }
        }
        
        return $style;
    }
    
    /**
     * 移除控制字符
     */
    private function stripControlChars(string $s): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    }
    
    /**
     * 检查是否为 JSON
     */
    private function isJson(string $contentType): bool
    {
        return (bool)preg_match('#application/(?:[\w.+-]*\+)?json#i', $contentType);
    }
}
