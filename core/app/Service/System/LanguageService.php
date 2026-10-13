<?php
namespace App\Service\System;

/**
 * 多语言服务（语言包装载、切换与缺键回退）
 * 语言包文件：lang/{code}.php（如 zh_cn.php / en_us.php）
 * 解析链：当前语言包 → zh_cn 回退 → 'lang[key]' 占位
 */
class LanguageService
{
    private static $packages = array();

    /**
     * 加载语言包（静态缓存）
     */
    public static function load(string $lang): array
    {
        if (isset(self::$packages[$lang])) {
            return self::$packages[$lang];
        }
        $file = APP_PATH . 'core/lang/' . preg_replace('#[^a-zA-Z0-9_\-]#', '', $lang) . '.php';
        // zh_cn 也需可加载：入口 $_SERVER['lang'] 可能被 App 容器快照遮蔽（install 等场景），回退链必须能自行加载 zh_cn
        $pkg = is_file($file) ? (include $file) : array();
        self::$packages[$lang] = is_array($pkg) ? $pkg : array();
        return self::$packages[$lang];
    }

    /**
     * 取语言键：当前语言包 → zh_cn 回退 → 占位符
     * @param string $lang 空=当前语言（conf['cms_lang']，默认 zh_cn）
     */
    public static function get(string $key, string $lang = '', array $arr = array()): string
    {
        if ('' === $lang) {
            $conf = _SERVER('conf');
            // cms_lang 才是站点语言配置；conf['lang'] 是视频筛选的语言列表（审计修复）
            $lang = (string)($conf['cms_lang'] ?? 'zh_cn');
            '' === $lang and $lang = 'zh_cn';
        }
        $pkg = self::load($lang);
        if (isset($pkg[$key])) {
            return self::replace($pkg[$key], $arr);
        }
        // zh_cn 回退
        if ('zh_cn' !== $lang) {
            $zh = self::load('zh_cn');
            if (isset($zh[$key])) {
                return self::replace($zh[$key], $arr);
            }
        }
        return 'lang[' . $key . ']';
    }

    /**
     * 语言占位符替换：把文案里的 {key} 逐个 str_replace 成对应值（值先 (string) 归一）。
     * 纯字面替换——非 eval、不支持嵌套/表达式/默认值语法；键名来自调用方数组，不做白名单校验。
     * 只做**一次**替换，替换值里再出现 {other} 不会被继续展开（无递归展开风险）。
     * 输出未转义：模板层 {$var} 是裸 echo（CLAUDE.md「安全红线·转义分层」），传进 $arr 的外部值需调用方自行转义。
     * @param string $s   文案原文（签名已声明 string）
     * @param array  $arr 占位符替换表 array('length'=>5) ⇒ 文案中的 {length} 被换掉（签名已声明 array）；空数组直接返回原文
     * @return string 替换后的文案
     */
    private static function replace(string $s, array $arr): string
    {
        if (!empty($arr)) {
            foreach ($arr as $k => $v) {
                $s = str_replace('{' . $k . '}', (string)$v, $s);
            }
        }
        return $s;
    }
}
