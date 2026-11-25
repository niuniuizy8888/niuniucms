<?php
!defined('DEBUG') and exit('Access Denied.');


// hook route_danmu_start.php
ob_clean();
$action = param(1);
switch($action){
    case 'list':
        $vid = intval(param('vid'));
        $max = max(1, min(intval(param('max', 100)), 1000)); // 下限 1 上限 1000，阻断 max=0 省略 LIMIT 全表拉取
        $format = param('format');
        $data = \App\Service\Front\DanmuService::buildList($vid, $max, intval($gid ?? 0), $conf);
        if (!empty($data['forbidden'])) {
            http_response_code(intval($data['status'] ?? 403));
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array('code' => 0, 'msg' => $data['msg'] ?? '无权访问', 'data' => array(), 'total' => 0), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            exit;
        }
        $danmu_data = $data['danmu_data'];
        if($format=='xml') {
            ob_end_clean();
            header('Content-Type: text/xml; charset=utf-8');
            header('Cache-Control: no-cache, must-revalidate');
            echo \App\Service\Content\DanmuService::formatXml($danmu_data, $vid);
            exit;
        } else {
            // 返回JSON格式数据 
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array('code' => 1, 'data' => $danmu_data, 'total' => count($danmu_data)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            exit;
        }
        break;
    case 'token':
        if (isset($conf['danmu']['danmu_toggle']) && 0 === intval($conf['danmu']['danmu_toggle'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array('code' => 0, 'msg' => '弹幕功能已关闭'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            exit;
        }
        if (!\App\Service\System\ThrottleService::check('danmu_token', (string)(_SERVER('ip') ?: ip()), 30, 60)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array('code' => 0, 'msg' => '操作过于频繁，请稍后再试'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            exit;
        }
        $check = \App\Service\Content\DanmuService::validateSendRequest($conf);
        if (empty($check['ok'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array('code' => 0, 'msg' => $check['msg']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, must-revalidate');
        echo json_encode(array('code' => 1, 'token' => bull_token_set(0, 'danmu_send')), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        exit;
        break;
    case 'send':
        $result = \App\Service\Front\DanmuService::send($conf, $uid, $user);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        exit;
        break;
    default:
        // 发布前检查修复（D-2）：未知 action → 404 兜底（route/user.php 同款），消除空 200 扫描噪音
        http_response_code(404);
        include _include(APP_PATH . '404.html');
        break;
}
// hook route_danmu_end.php
