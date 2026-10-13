<?php
namespace App\Service\Front;

/**
 * SEO 模板变量装配（原 core.php seo_apply/seo_replace_vars 下沉）
 */
class SeoService
{
    /**
     * 替换模板变量 {$key} 占位符
     */
    public static function replaceVars($template, $vars = [])
    {
        foreach ($vars as $key => $val) {
            $template = str_replace('{$' . $key . '}', $val, $template);
        }
        return $template;
    }

    /**
     * 应用 SEO 配置到请求级 $seo 全局状态（模板 {$seo.t/$seo.k/$seo.d} 与 API $api_json['seo'] 读取）
     * @param array $tpl_array SEO 配置段（seo_title/seo_key/seo_des）
     * @param array $vars 变量替换表
     * @param array $meta 页面级附加 SEO 元数据（canonical/og_image/og_url/robots/jsonld 等）
     */
    public static function apply($tpl_array, $vars = [], $meta = [])
    {
        global $seo;
        isset($seo) || $seo = array();
        $keys = ['seo_title' => 't', 'seo_key' => 'k', 'seo_des' => 'd'];
        foreach ($keys as $k => $h) {
            if (!empty($tpl_array[$k])) {
                $seo[$h] = self::replaceVars($tpl_array[$k], $vars);
            }
        }
        // 页面级 SEO 元数据（全部防覆写：未提供时保持既有值，模板 {$seo.xxx} 条件输出）
        foreach ($meta as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            if (isset($seo[$k]) && $seo[$k] !== '') {
                continue;
            }
            $seo[$k] = $v;
        }
    }

    /**
     * 当前请求规范 URL（canonical 缺省值）
     * - 伪静态（rewrite>=2）：去 query（页面标识在 path 段）
     * - 动态（rewrite<2）：保留完整 REQUEST_URI（页面标识 .html 在 query 内，去 query 会丢页面）
     */
    public static function currentUrl()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        if (empty($uri)) {
            $uri = '/';
        }
        $conf = _SERVER('conf');
        $rewrite = intval(is_array($conf) ? ($conf['url_rewrite_on'] ?? 0) : 0);
        if ($rewrite >= 2) {
            $uri = strtok($uri, '?');
            if ($uri === '' || $uri === '/') {
                $uri = '/';
            }
        }
        $base = rtrim(http_url_path(), '/');
        // 上线前安全审计修复（反射型 XSS，VULN-003）：
        // REQUEST_URI/Host 原样反射进 <link rel="canonical" href="{$seo.url}"/> 与
        // <meta property="og:url" content="{$seo.url}">（public/include.html），
        // 请求行含原始引号即可闭合属性注入标签。仅用于属性上下文，收尾统一转义。
        return htmlspecialchars($base . '/' . ltrim($uri, '/'), ENT_QUOTES, 'UTF-8');
    }
}
