<?php
namespace App\Service\Admin\Traits;

use App\Service\Admin\ConfigRuntimeService;

/**
 * 站点设置（websitePage/saveWebsite）（从 SetManageService 拆分，Trait 组合）
 */
trait SetManageWebsiteTrait
{
    /**
     * 后台「站点设置」GET 视图数据：逐键 form_text/radio/textarea 生成控件 HTML
     * （sitename/logo/pagesize…/tpl_mode，键名与 saveWebsite 白名单一一对应），
     * 附 admin_set 场景 safe_token。调用方 core/admin/route/set.php（extract + set.html）。
     * @param array $conf 站点当前配置（控件预填值来源）
     * @param array $config 含 setting 层的完整配置（array_value($config,'setting') 取模板模式等）
     * @param int $uid 管理员 UID（token 场景指纹绑定）
     * @return array {header, setting, input: 控件 HTML 集, safe_token}
     */
    public static function websitePage(array $conf, array $config, int $uid): array
    {
        $setting = array_value($config, 'setting');
        $tplModeArr = array('0' => lang('mode_0'), '1' => lang('mode_1'), '2' => lang('mode_2'));

        return array(
            'header' => array(
                'title' => lang('set_web'),
            ),
            'setting' => $setting,
            'input' => array(
                'sitename' => form_text('sitename', self::decodeStoredText($conf['sitename'])),
                'logo' => form_text('logo', $conf['logo']),
                'pagesize' => form_text('pagesize', $conf['pagesize']),
                'postlist_pagesize' => form_text('postlist_pagesize', $conf['postlist_pagesize']),
                'listsize' => form_text('listsize', $conf['listsize']),
                'linksize' => form_text('linksize', $conf['linksize']),
                'tagsize' => form_text('tagsize', $conf['tagsize']),
                'tag_num' => form_text('tag_num', $conf['tag_num'] ?? 5),
                'cdn_on' => form_radio('cdn_on', array('1' => '开', '0' => '关'), array_value($conf, 'cdn_on', 0)),
                'api_on' => form_radio('api_on', array('1' => '开', '0' => '关'), array_value($conf, 'api_on', 0)),
                'search_on' => form_radio('search_on', array('1' => '开', '0' => '关'), array_value($conf, 'search_on', 1)),
                'sitemap_tag' => form_radio('sitemap_tag', array('1' => '开', '0' => '关'), array_value($conf, 'sitemap_tag', 0)),
                'sitemap_play' => form_radio('sitemap_play', array('1' => '开', '0' => '关'), array_value($conf, 'sitemap_play', 0)),
                'sitemap_num' => form_text('sitemap_num', $conf['sitemap_num']),
                'down_pic' => form_radio_yes_no('down_pic', $conf['down_pic']),
                'area' => form_text('area', self::decodeStoredText($conf['area'])),
                'lang' => form_text('lang', self::decodeStoredText($conf['lang'])),
                'year' => form_text('year', self::decodeStoredText($conf['year'])),
                'agg' => form_textarea('agg', $conf['agg'], '', '100%', 100),
                'tg' => form_text('tg', $conf['tg']),
                'email' => form_text('email', $conf['email']),
                'tpl_mode' => form_radio('tpl_mode', $tplModeArr, array_value($setting, 'tpl_mode', 0)),
            ),
            'safe_token' => bull_token_set($uid, 'admin_set'),
        );
    }

    /**
     * 入库已 htmlspecialchars 的文本（sitename/area/lang/year）回显前还原原文，
     * 交给 form_text 统一转义一次——否则每「打开→保存」一轮就多一层 &amp;。
     * @param mixed $v 配置存储值
     * @return string 原文
     */
    private static function decodeStoredText($v): string
    {
        return html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 后台「站点设置」POST 保存：先 verifyToken($uid,'admin_set') CSRF 闸（fail-closed），
     * 逐键 admin_param_int（区间钳制）/admin_param_text（长度上限）白名单取参——
     * 只认这份键清单，外部多传键进不了落库面；转发
     * ConfigRuntimeService::saveWebsiteSettings（出口侧转义在那里做，红线 §二.4；
     * 写 KV 运行时层，不物理改写 config.php，CLAUDE.md「当前架构·配置分层」）。
     * @param array $config 完整配置（作 saveWebsiteSettings 的合并底）
     * @param int $uid 管理员 UID
     * @return array {code, message} 供 message() 输出（现行 saveWebsiteSettings 恒成功，code 固定 0）
     */
    public static function saveWebsite(array $config, int $uid): array
    {
        self::verifyToken($uid, 'admin_set');
        // ⚠ 列表型字段（地区/年份/语言）**不能走 admin_param_text 的字节截断**（真机血案）：
        //   旧实现上限 area=100 / lang=50 / year=20 **字节**，而逗号列表远超此值 ⇒ 保存时被静默切断，
        //   实测 area 截到「英」（"英国"被切一半）、year 只留 4 项且尾随逗号、lang 整组为空
        //   ⇒ 前台 lists/types 页的筛选行出现**空白项**、语言整组空。这里改为三步：
        //   ① 无截断读入（admin_param_text 第三参 0 = 不限长，见该函数 `$max and substr(...)`）；
        //   ② 归一（中文逗号/顿号/竖线/分号/空白 → 英文逗号，逐项去空白、去空项、去重保序）；
        //   ③ 超出上限**明确报错**，绝不静默截断（静默截断正是本缺陷的成因）。
        $listMax = array('area' => 500, 'lang' => 300, 'year' => 500);
        $listLabel = array('area' => '地区', 'lang' => '语言', 'year' => '年份');
        $normalized = array();
        foreach ($listMax as $k => $maxBytes) {
            $normalized[$k] = self::normalizeListField(admin_param_text($k, '', 0));
            if (strlen($normalized[$k]) > $maxBytes) {
                return array('code' => -1, 'message' => sprintf(
                    '%s 取值过长（%d 字节，上限 %d 字节）：请精简后再保存。'
                    . '（旧实现在此处直接截断，会导致前台筛选行出现空白项/整组丢失）',
                    $listLabel[$k], strlen($normalized[$k]), $maxBytes
                ));
            }
        }
        $payload = array(
            'sitename' => admin_param_text('sitename', '', 100),
            'logo' => admin_param_text('logo', '', 255),
            'pagesize' => admin_param_int('pagesize', 20, 1, 200),
            'postlist_pagesize' => admin_param_int('postlist_pagesize', 20, 1, 200),
            'listsize' => admin_param_int('listsize', 20, 1, 200),
            'linksize' => admin_param_int('linksize', 20, 1, 200),
            'tagsize' => admin_param_int('tagsize', 20, 1, 200),
            'tag_num' => admin_param_int('tag_num', 20, 1, 200),
            'cdn_on' => admin_param_int('cdn_on', 0, 0, 1),
            'api_on' => admin_param_int('api_on', 0, 0, 1),
            'search_on' => admin_param_int('search_on', 0, 0, 1),
            'sitemap_tag' => admin_param_int('sitemap_tag', 0, 0, 1),
            'sitemap_play' => admin_param_int('sitemap_play', 0, 0, 1),
            'sitemap_num' => admin_param_int('sitemap_num', 100, 0, 100000),
            'down_pic' => admin_param_int('down_pic', 0, 0, 1),
            'area' => $normalized['area'],
            'lang' => $normalized['lang'],
            'year' => $normalized['year'],
            'agg' => admin_param_text('agg', '', 500),
            'tg' => admin_param_text('tg', '', 500),
            'email' => admin_param_text('email', '', 100),
            'tpl_mode' => admin_param_int('tpl_mode', 0, 0, 2),
            'website_mode' => admin_param_int('website_mode', 0, 0, 1),
        );
        $result = ConfigRuntimeService::saveWebsiteSettings($payload, $config);
        return array('code' => 0, 'message' => $result['msg']);
    }

    /**
     * 逗号列表字段归一（地区/年份/语言等）：中文逗号、顿号、竖线、分号与空白统一成英文逗号，
     * 逐项去空白、**去空项**、去重（保持原顺序）。
     * 去空项是硬要求：前台筛选行是按项渲染的，尾随逗号会产生一个**空白 pill**（实测 symptom）。
     * ⚠ 不做长度截断——长度上限由调用方校验并明确报错（静默截断＝本缺陷成因）。
     * @param string $v 原始输入
     * @return string 归一后的 `a,b,c` 形态（可能为空串）
     */
    private static function normalizeListField(string $v): string
    {
        $v = str_replace(array('，', '、', '｜', '|', '；', ';'), ',', $v);
        $v = preg_replace('/\s+/u', '', $v);
        $out = array();
        foreach (explode(',', (string)$v) as $item) {
            $item = trim($item);
            if ('' === $item || in_array($item, $out, TRUE)) continue;
            $out[] = $item;
        }
        return implode(',', $out);
    }
}
