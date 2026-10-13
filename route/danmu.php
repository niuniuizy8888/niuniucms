<?php
!defined('DEBUG') and exit('Access Denied.');


// hook route_danmu_start.php
ob_clean();
$action = param(1);
switch($action){
    case 'list':
        $vid = intval(param('vid'));
        $max = min(intval(param('max', 100)), 1000); // 上限 1000，防超大拉取（审计修复）
        $format = param('format');
        $data = \App\Service\Front\DanmuService::buildList($vid, $max);
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
        // 弹幕发送令牌的「现签」端点（第二十二轮）。
        // 为什么需要它：前台 L0 页面缓存（extension/www.example.com/perf.conf）对匿名 GET 一律
        // fastcgi_hide_header Set-Cookie —— 匿名访客的请求拿不到 nncms_sid，
        // 播放页 route/play.php 里现签的 danmu_send 令牌因此属于一个浏览器从不知道的会话，
        // 下一次请求又是一份全新会话 ⇒ 场景指纹环必空 ⇒ 匿名发弹幕恒报「非法操作」。
        // POST 在 L0 规则里天然回源（$nncms_isget 默认 1 → skipcache），Set-Cookie 正常下发，
        // 所以把「签发」这一步挪到 POST 端点：本请求建立并落盘会话，令牌环写进这份会话。
        // 校验链本身一个字没放宽：签出的仍是 bull_token_set(0,'danmu_send')，
        // 发送侧仍走 danmu_send 环 + ip/uid/UA 绑定 + Origin/Referer 同源闸。
        // 令牌端点自身要限流：Origin/Referer 是非浏览器请求可伪造的头，不加闸等于开放「批量建会话」入口
        // （每次 POST 都会落一份匿名会话）。30 次/分钟/IP 远超真人行为。
        if (!\App\Service\System\ThrottleService::check('danmu_token', (string)(_SERVER('longip') ?: ip()), 30, 60)) {
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
