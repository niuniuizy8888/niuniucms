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
    public static function buildList($vid, $max, ?int $gid = null, ?array $conf = null)
    {
        $vid = intval($vid);
        $max = max(1, min(intval($max), 1000));
        $conf = $conf ?? (_SERVER('conf') ?: array());
        if (isset($conf['danmu']['danmu_toggle']) && 0 === intval($conf['danmu']['danmu_toggle'])) {
            return array('vid' => $vid, 'danmu_data' => array(), 'total' => 0, 'forbidden' => true, 'status' => 403, 'msg' => '弹幕功能已关闭');
        }
        $video = $vid > 0 ? \App\Service\Content\VideoService::read($vid) : null;
        if (empty($video)) {
            return array('vid' => $vid, 'danmu_data' => array(), 'total' => 0, 'forbidden' => true, 'status' => 404, 'msg' => '视频不存在');
        }
        $groupId = $gid !== null ? $gid : intval($GLOBALS['gid'] ?? 0);
        if (!\App\Service\Content\CateService::accessUser(intval($video['cid'] ?? 0), $groupId, 'allowread')) {
            return array('vid' => $vid, 'danmu_data' => array(), 'total' => 0, 'forbidden' => true, 'status' => 403, 'msg' => '无权访问该视频弹幕');
        }
        $danmu_list = \App\Service\Content\DanmuService::listByVideo($vid, $max);
        $danmu_data = \App\Service\Content\DanmuService::formatData($danmu_list);
        return array(
            'vid' => $vid,
            'danmu_data' => $danmu_data,
            'total' => count($danmu_data),
        );
    }

    public static function send($conf, $uid, $user)
    {
        if (isset($conf['danmu']['danmu_toggle']) && 0 === intval($conf['danmu']['danmu_toggle'])) {
            return array('code' => 0, 'msg' => '弹幕功能已关闭');
        }
        // CSRF token（游客 UA 绑定，播放页下发；审计修复）
        if (FALSE === bull_token_verify(0, param('safe_token'), 'danmu_send')) {
            return array('code' => 0, 'msg' => '非法操作');
        }
        $throttle_key = (string)(_SERVER('ip') ?: ip());
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
        $video = \App\Service\Content\VideoService::read(intval($data['vid'] ?? 0));
        if (empty($video)) {
            return array('code' => 0, 'msg' => '视频不存在');
        }
        $groupId = intval(is_array($user) ? ($user['gid'] ?? ($GLOBALS['gid'] ?? 0)) : ($GLOBALS['gid'] ?? 0));
        if (!\App\Service\Content\CateService::accessUser(intval($video['cid'] ?? 0), $groupId, 'allowread')) {
            return array('code' => 0, 'msg' => '无权对该视频发表弹幕');
        }

        $result = \App\Service\Content\DanmuService::create($data);
        if ($result) {
            return array('code' => 1, 'msg' => '发送成功', 'data' => $data);
        }
        return array('code' => 0, 'msg' => '发送失败');
    }
}
