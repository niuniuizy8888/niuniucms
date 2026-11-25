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
        // 剥离引号/尖括号/控制字符与反斜杠（URL 本身不含这些字符），避免在裸输出模板逃逸属性，
        // 同时不在源头把 & 编码成 &amp; 以免 {h:$seo.url} 二次转义成 &amp;amp;。
        return (string)preg_replace('/[\x00-\x1F\x7F"\'`<>\\\\]/', '', $base . '/' . ltrim($uri, '/'));
    }

    /**
     * 基于实体规范相对 URL 拼装绝对 canonical URL，避免整页缓存把任意未入键查询串写进 canonical/JSON-LD。
     */
    public static function canonicalUrl(string $relUrl = ''): string
    {
        if ('' === trim($relUrl)) {
            return self::currentUrl();
        }
        $base = rtrim(http_url_path(), '/');
        if (preg_match('#^https?://#i', $relUrl)) {
            $url = $relUrl;
        } else {
            $rel = ltrim((string)preg_replace('#^(?:\./)+#', '', $relUrl), '/');
            $url = $base . '/' . $rel;
        }
        return (string)preg_replace('/[\x00-\x1F\x7F"\'`<>\\\\]/', '', $url);
    }
}
