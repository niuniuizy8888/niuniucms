<?php
namespace App\Service\Content;

class DanmuService
{
    /**
     * 取某视频已发布弹幕：status=1、按视频内时间轴升序，上限 $max 条（danmu 表，结果以记录 id 为键）。
     * @param mixed $vid 视频 ID（内部 intval）
     * @param int   $max 条数上限（内部 intval，默认 100）
     * @return array 弹幕行列表；无结果为空数组
     */
    public static function listByVideo($vid, $max = 100)
    {
        $rows = \App\Repository\RepositoryFactory::danmu()->find(
            array('vid' => intval($vid), 'status' => 1),
            array('time' => 1),
            1,
            intval($max),
            'id'
        );
        return $rows ?: array();
    }

    /**
     * 弹幕数据格式化（轻量字段装配）
     * @param array $danmu_list 原始弹幕数据列表
     * @return array 格式化后的弹幕数据
     */
    public static function formatData($danmu_list)
    {
        $danmu_data = [];
        foreach ($danmu_list as $danmu) {
            $danmu_data[] = [
                'time' => floatval($danmu['time']),
                // text 入库为转义值（buildSendPayload），播放 JSON 输出前还原一次；存量未转义行原样通过
                'text' => htmlspecialchars_decode((string)$danmu['text'], ENT_QUOTES),
                'color' => $danmu['color'],
                'mode' => intval($danmu['mode']),
                'username' => isset($danmu['username']) ? $danmu['username'] : ''
            ];
        }
        return $danmu_data;
    }

    /**
     * 弹幕 XML 输出（bilibili 弹幕格式）
     * @param array $danmu_list 格式化后的弹幕数据
     * @param int $vid 视频 ID
     * @return string
     */
    public static function formatXml($danmu_list, $vid)
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<i>\n";
        $xml .= "<chatserver>chat.bilibili.com</chatserver>\n";
        $xml .= "<chatid>" . intval($vid) . "</chatid>\n";
        $xml .= "<mission>0</mission>\n";
        $xml .= "<maxlimit>10000</maxlimit>\n";
        $xml .= "<state>0</state>\n";
        $xml .= "<real_name>0</real_name>\n";

        foreach ($danmu_list as $danmu) {
            // 转换颜色格式
            $color = ltrim($danmu['color'], '#');
            if (strlen($color) === 6) {
                $r = hexdec(substr($color, 0, 2));
                $g = hexdec(substr($color, 2, 2));
                $b = hexdec(substr($color, 4, 2));
                $color_dec = ($r << 16) | ($g << 8) | $b;
            } else {
                $color_dec = 16777215; // 默认白色
            }

            // 转换模式
            switch (intval($danmu['mode'])) {
                case 1:
                    $mode_bili = 5;
                    break; // 顶部
                case 2:
                    $mode_bili = 4;
                    break; // 底部
                default:
                    $mode_bili = 1;
                    break; // 滚动
            }

            $timestamp = time();
            $uid = isset($danmu['username']) && $danmu['username']
                ? abs(crc32($danmu['username']))
                : mt_rand(1000000, 9999999);
            $text = htmlspecialchars($danmu['text'], ENT_QUOTES, 'UTF-8');

            $xml .= "<d p=\"" . number_format($danmu['time'], 2, '.', '') . ",$mode_bili,25,$color_dec,$timestamp,0,$uid\">$text</d>\n";
        }

        $xml .= "</i>";
        return $xml;
    }

    /**
     * 从请求参数组装待入库弹幕行（发送接口的"输入侧"）。转义边界（红线 §二.4）：
     * text 取原始值（param 第三参 FALSE）→ mb_substr 截 100 字 → htmlspecialchars(ENT_QUOTES) 转义后入库，
     * 播放 JSON 由 formatData() 输出前还原一次；username 同样转义后存（空则"游客"）。
     * 其余字段白名单/钳制：color 正则校验非法回白色、time 钳 0~86400、mode 钳 0~2、ip 存 long（IPv4 外存 0）。
     * @param mixed $uid  发送者 uid（内部 intval；游客传 0/空）
     * @param array $user 当前用户行（只读 username 键，游客场景可为空数组）
     * @return array 可直接交给 create() 入库的完整行（status=1 直接发布，无审核态）
     */
    public static function buildSendPayload($uid, $user)
    {
        $vid = intval(param('vid'));
        if (empty($vid) && isset($_REQUEST['vod_id'])) {
            $vid = intval(param('vod_id'));
        }
        $text = param('text', '', false);
        $text = mb_substr($text, 0, 100, 'UTF-8');
        // 红线「入库必须转义」：text 转义入库，播放 JSON 输出侧 formatData() 统一 htmlspecialchars_decode 还原
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $color = param('color');
        if (!preg_match('/^#?[0-9a-fA-F]{6}$/', $color)) {
            $color = '#FFFFFF';
        }

        $username = isset($user['username']) && $user['username'] ? htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') : '游客';
        $time = min(86400, max(0, floatval(param('time', '0')))); // 0-24h 钳制（审计修复）
        $mode = min(2, max(0, intval(param('mode', 0)))); // 0滚动/1顶部/2底部
        $client_ip = ip();
        $ip_long = ip2long($client_ip);

        return array(
            'vid' => $vid,
            'uid' => intval($uid),
            'username' => $username,
            'text' => $text,
            'time' => $time,
            'color' => $color,
            'mode' => $mode,
            'ip' => $ip_long > 0 ? $ip_long : 0,
            'create_time' => time(),
            'status' => 1,
        );
    }

    /**
     * 弹幕发送接口的同源 CSRF 前置闸（fail-closed）：拒 GET；缺 Origin/Referer 拒；
     * Origin 为 null/非法/非 http(s) 协议拒；否则把 Origin 与候选（cookie_domain、HTTP_HOST）
     * 归一成小写 host:port 精确比对（缺省端口按协议补 80/443），不相等一律拒。
     * 第二十二轮修复：只比 host 不比端口会把非 80/443 站点的同源请求误判为跨域。
     * @param array $conf 站点配置（读 cookie_domain；调用方传全局 conf）
     * @return array array('ok'=>true) 或 array('ok'=>false,'msg'=>拒绝原因)
     */
    public static function validateSendRequest($conf)
    {
        if (_SERVER('REQUEST_METHOD') === 'GET') {
            return array('ok' => false, 'msg' => '不允许GET访问');
        }

        $allowed_origins = array($conf['cookie_domain'] ?: (_SERVER('HTTP_HOST') ?? ''));
        $origin = _SERVER('HTTP_ORIGIN') ?? (_SERVER('HTTP_REFERER') ?? '');
        if (!$origin) {
            return array('ok' => false, 'msg' => '缺少 Origin/Referer 头，疑似 CSRF 攻击');
        }
        $origin_host = parse_url($origin, PHP_URL_HOST);
        if (!is_string($origin_host) || $origin_host === '' || strtolower($origin_host) === 'null') {
            // Origin: null（sandboxed iframe/file 协议等）与非法 Origin 一律拒绝（审计修复）
            return array('ok' => false, 'msg' => '拒绝跨域请求');
        }
        // 同源判定必须连端口一起比（第二十二轮修）：parse_url(PHP_URL_HOST) 拿到的
        // host 段永远不含端口，而 HTTP_HOST 在非 80/443 站点是 "192.0.2.3:2001" 这种带端口形式，
        // 于是旧的字符串比较把本站自己的弹幕请求判成「跨域」——表现为「弹幕一条也发不出去」，
        // 与令牌/会话无关（审计引入的口径缺陷）。两侧统一归一成 host:port 再比；
        // cookie_domain 仍是精确匹配，".test.com" 不会让 cdn.test.com 蒙混过关（保持 fail-closed）。
        $origin_scheme = strtolower((string) parse_url($origin, PHP_URL_SCHEME));
        if ('http' !== $origin_scheme && 'https' !== $origin_scheme) {
            return array('ok' => false, 'msg' => '拒绝跨域请求');
        }
        $origin_port = parse_url($origin, PHP_URL_PORT);
        $origin_port = ($origin_port === null || $origin_port === '')
            ? ('https' === $origin_scheme ? '443' : '80') : (string) $origin_port;
        $origin_authority = strtolower($origin_host) . ':' . $origin_port;
        $candidates = array_merge($allowed_origins, array((string) (_SERVER('HTTP_HOST') ?? '')));
        $matched = FALSE;
        foreach ($candidates as $allow) {
            if ($allow === '' || str_contains($allow, '://')) {
                continue;
            }
            if (self::normalizeAuthority($allow) === $origin_authority) {
                $matched = TRUE;
                break;
            }
        }
        if (!$matched) {
            return array('ok' => false, 'msg' => '拒绝跨域请求');
        }

        return array('ok' => true);
    }

    /**
     * "host" / "host:port" → "host:port"（缺省端口按请求协议补齐）
     */
    private static function normalizeAuthority($authority)
    {
        $authority = strtolower(trim((string) $authority));
        $host = $authority;
        $port = '';
        $pos = strrpos($authority, ':');
        if ($pos !== FALSE && strpos($authority, ':', $pos + 1) === FALSE) {
            $host = substr($authority, 0, $pos);
            $port = substr($authority, $pos + 1);
        }
        if ($port !== '' && !ctype_digit($port)) {
            // IPv6 字面量等异常形态：整体当 host 处理，端口走默认补齐
            $host = $authority;
            $port = '';
        }
        $scheme = strtolower((string) (_SERVER('REQUEST_SCHEME') ?: ''));
        if ($scheme === '') {
            $https = (string) _SERVER('HTTPS', 'off');
            $scheme = ($https !== '' && 'off' !== $https) ? 'https' : 'http';
        }
        if ($port === '') {
            $port = ('https' === $scheme) ? '443' : '80';
        }
        return $host . ':' . $port;
    }

    /**
     * 弹幕载荷校验：必填 vid/text/color；防重复发送 = 120s 窗口内同 ip+text 只放行一次
     * （缓存键 danmu_check_md5(ip.text)，校验通过即占位——只看缓存不回查库，属限频非强幂等）。
     * @param array $data buildSendPayload() 产物（签名已声明）
     * @return array array('ok'=>true) 或 array('ok'=>false,'msg'=>失败原因)
     */
    public static function validatePayload(array $data)
    {
        if (empty($data['vid']) || empty($data['text']) || empty($data['color'])) {
            return array('ok' => false, 'msg' => '缺少必要参数：vid、text和color');
        }

        $danmu_cache_key = 'danmu_check_' . md5(ip() . $data['text']);
        $check_result = cache_get($danmu_cache_key);
        if ($check_result) {
            return array('ok' => false, 'msg' => '请勿重复发送相同弹幕');
        }

        cache_set($danmu_cache_key, 1, 120);
        return array('ok' => true);
    }

    /**
     * 写入一条弹幕：转发 RepositoryFactory::danmu()->insert()（QueryBuilder 预编译路径，不做业务校验——上游须先过 validateSendRequest/validatePayload）。
     * @param array $data 完整行数据（签名已声明）
     * @return int|FALSE 自增 id；$data 为空时 FALSE
     */
    public static function create(array $data)
    {
        return \App\Repository\RepositoryFactory::danmu()->insert($data);
    }
}