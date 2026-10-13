<?php
namespace App\Service\Front;

class DanmuService
{
    /**
     * 前台播放器取弹幕：聚合 Content\DanmuService::listByVideo（按 vid 拉原始行，$max 上限，无结果=空数组）
     * 与 formatData（转播放器可直接消费的格式化数据），组装成播放页一次性下发的载荷。
     * @param mixed $vid 视频 ID（透传，底层 intval）
     * @param int   $max 条数上限（透传，底层默认 100）
     * @return array ['vid'=>mixed, 'danmu_data'=>array 格式化弹幕集, 'total'=>int 条数]
     */
    public static function buildList($vid, $max)
    {
        $danmu_list = \App\Service\Content\DanmuService::listByVideo($vid, $max);
        $danmu_data = \App\Service\Content\DanmuService::formatData($danmu_list);
        return array(
            'vid' => $vid,
            'danmu_data' => $danmu_data,
            'total' => count($danmu_data),
        );
    }

    /**
     * 前台发弹幕（门禁链，全部 fail-closed，任一不过即 code=0）：
     * ① bull_token_verify(0, safe_token, 'danmu_send')——游客令牌 UA 绑定、播放页现签下发（§二.9 恒时口径），不过 ⇒「非法操作」；
     * ② VULN-004 双桶限流：IP 30 条/60s + 登录态 uid 30 条/60s（防「场景环内复用令牌 + 变换文本」无限灌）；
     * ③ Content\DanmuService::validateSendRequest($conf) 请求参数校验 + buildSendPayload 组装后 validatePayload 载荷校验（文本/长度等白名单口径）。
     * 通过才 create() 落库。
     * @param array $conf 配置数组（透传校验层）
     * @param int   $uid  当前用户 ID（0=游客）
     * @param mixed $user 用户行/上下文（透传 buildSendPayload）
     * @return array ['code'=>0|1, 'msg'=>string, 'data'=>发成功的载荷（仅 code=1 时存在）]——HTTP 层不因失败抛错，按 code 判
     */
    public static function send($conf, $uid, $user)
    {
        // CSRF token（游客 UA 绑定，播放页下发；审计修复）
        if (FALSE === bull_token_verify(0, param('safe_token'), 'danmu_send')) {
            return array('code' => 0, 'msg' => '非法操作');
        }
        // 上线前安全审计修复（VULN-004）：发送路径自身限流。
        // 此前仅「令牌现签端点」限流（danmu_token 30/60s）+ 同 IP 同文本 120s 去重，
        // 而令牌在 12 条场景环窗口内可复用 ⇒ 变换文本即可无限灌弹幕。
        // 30 条/60s/IP 远超真人打字速度；登录态另按 uid 计一桶。
        $throttle_key = (string)(_SERVER('longip') ?: ip());
        if (!\App\Service\System\ThrottleService::check('danmu_send', $throttle_key, 30, 60)) {
            return array('code' => 0, 'msg' => '操作过于频繁，请稍后再试');
        }
        if ($uid > 0 && !\App\Service\System\ThrottleService::check('danmu_send_uid', (string)intval($uid), 30, 60)) {
            return array('code' => 0, 'msg' => '操作过于频繁，请稍后再试');
        }
        $validate_request = \App\Service\Content\DanmuService::validateSendRequest($conf);
        if (empty($validate_request['ok'])) {
            return array('code' => 0, 'msg' => $validate_request['msg']);
        }

        $data = \App\Service\Content\DanmuService::buildSendPayload($uid, $user);
        $validate_payload = \App\Service\Content\DanmuService::validatePayload($data);
        if (empty($validate_payload['ok'])) {
            return array('code' => 0, 'msg' => $validate_payload['msg']);
        }

        $result = \App\Service\Content\DanmuService::create($data);
        if ($result) {
            return array('code' => 1, 'msg' => '发送成功', 'data' => $data);
        }
        return array('code' => 0, 'msg' => '发送失败');
    }
}
