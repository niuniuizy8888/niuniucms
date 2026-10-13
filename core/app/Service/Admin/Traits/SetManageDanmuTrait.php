<?php
namespace App\Service\Admin\Traits;

use App\Service\Admin\ConfigRuntimeService;

/**
 * 弹幕配置（danmuPage/saveDanmu）（从 SetManageService 拆分，Trait 组合）
 */
trait SetManageDanmuTrait
{
    /**
     * 弹幕设置页（GET）渲染数据装配：header/input/safe_token 三段契约，供 set_danmu.html 经
     * extract(EXTR_SKIP) 后进模板。input 各项由 form_radio/form_text 生成；
     * safe_token = bull_token_set 发 'admin_set_danmu' 场景指纹环（与 saveDanmu 的门禁配对）。
     * ⚠ 本配置族沿用历史口径「0=开启」：on=0 是弹幕开启（消费端 PlayService 判 0===on），radio 标签同此。
     * @param array $conf 站点配置（取 conf['danmu'] 子树，缺项按默认值渲染）
     * @param int $uid 当前管理员 uid（令牌主体）
     * @return array 模板变量集（键见方法体 return）
     */
    public static function danmuPage(array $conf, int $uid): array
    {
        $danmu = array_value($conf, 'danmu', array());
        return array(
            'header' => array(
                'title' => lang('danmu_set'),
            ),
            'input' => array(
                'on' => form_radio('on', array('0' => '开启', '1' => '关闭'), array_value($danmu, 'on', 0)),
                // 'cdn' 开关已移除：播放器依赖固定走本站 /static/，不再支持公共 CDN
                // （供应链投毒面）。admin/html/set_danmu.html 已同步去掉该栏位。
                'start_ad' => form_radio('start_ad', array('0' => '图片', '1' => '视频'), array_value($danmu, 'start_ad', 0)),
                'danmu_toggle' => form_radio('danmu_toggle', array('0' => '开启', '1' => '关闭'), array_value($danmu, 'danmu_toggle', 0)),
                'pause_ad' => form_radio('pause_ad', array('0' => '开启', '1' => '关闭'), array_value($danmu, 'pause_ad', 0)),
                'selec_col' => form_radio('selec_col', array('0' => '开启', '1' => '关闭'), array_value($danmu, 'selec_col', 0)),
                'start_ad_on' => form_radio('start_ad_on', array('0' => '开启', '1' => '关闭'), array_value($danmu, 'start_ad_on', 0)),
                'start_img_ad' => form_text('start_img_ad', array_value($danmu, 'start_img_ad', '')),
                'start_mp4_ad' => form_text('start_mp4_ad', array_value($danmu, 'start_mp4_ad', '')),
                'start_ad_url' => form_text('start_ad_url', array_value($danmu, 'start_ad_url', '')),
                'start_all_time' => form_text('start_all_time', array_value($danmu, 'start_all_time', '')),
                'start_close_time' => form_text('start_close_time', array_value($danmu, 'start_close_time', '')),
                'stop_ad' => form_text('stop_ad', array_value($danmu, 'stop_ad', '')),
                'stop_ad_url' => form_text('stop_ad_url', array_value($danmu, 'stop_ad_url', '')),
            ),
            'safe_token' => bull_token_set($uid, 'admin_set_danmu'),
        );
    }

    /**
     * 弹幕设置保存（POST）：先 verifyToken 场景门禁（'admin_set_danmu' 指纹环，不过即拒=fail-closed），
     * 开关项经 admin_param_int 区间钳制取参；5 个广告 URL 字段走 $ad_url 红线闸——
     * 仅放行 http/https 或站内相对路径（/ 起始），且含 <>"' 反引号/控制字符/反斜杠即 message(1) 拒
     * （前台把该族值以 src 属性与 window.open('…') JS 字面量**裸输出**，模板层无转义，引号可逃逸，
     * 见 §二.4 输出侧红线），入库走 ConfigRuntimeService::saveDanmu 写 KV 运行时层（不再改物理 config.php）。
     * @param int $uid 当前管理员 uid（令牌校验主体）
     * @return array ['code'=>0, 'message'=>jump(...) 跳转提示 HTML]——失败路径（令牌/URL 闸）均经 message/抛错终止，能返回即已落库
     */
    public static function saveDanmu(int $uid): array
    {
        self::verifyToken($uid, 'admin_set_danmu');
        // 广告 URL 红线闸：仅 http/https 或站内相对路径（前台以 src 属性与 window.open('…') JS 字面量裸输出，引号可逃逸）
        $ad_url = function ($name) {
            $v = trim(admin_param_text($name, '', 1024));
            if ($v === '') {
                return '';
            }
            if (!preg_match('#^(https?://|/)#i', $v) || preg_match('/[<>"\'`\x00-\x1F\x7F\\\\]/', $v)) {
                message(1, '广告地址仅支持 http/https 或站内相对路径');
            }
            return $v;
        };
        $payload = array(
            'on' => admin_param_int('on', 0, 0, 1),
            // 'cdn' 不再入库：播放器依赖固定走本站 /static/（见配置文件同名字段注释）
            'start_ad' => admin_param_int('start_ad', 0, 0, 1),
            'danmu_toggle' => admin_param_int('danmu_toggle', 0, 0, 1),
            'pause_ad' => admin_param_int('pause_ad', 0, 0, 1),
            'selec_col' => admin_param_int('selec_col', 0, 0, 1),
            'start_ad_on' => admin_param_int('start_ad_on', 0, 0, 1),
            'start_img_ad' => $ad_url('start_img_ad'),
            'start_mp4_ad' => $ad_url('start_mp4_ad'),
            'start_ad_url' => $ad_url('start_ad_url'),
            'start_all_time' => admin_param_int('start_all_time', 10, 0),
            'start_close_time' => admin_param_int('start_close_time', 5, 0),
            'stop_ad' => $ad_url('stop_ad'),
            'stop_ad_url' => $ad_url('stop_ad_url'),
        );
        $result = ConfigRuntimeService::saveDanmu($payload);
        return array('code' => 0, 'message' => jump($result['msg'], url('set-danmu', array(), TRUE), 3));
    }
}
