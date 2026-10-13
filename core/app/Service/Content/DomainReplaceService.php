<?php
namespace App\Service\Content;

/**
 * 域名替换执行器（新增）
 *
 * **唯一执行口径**：本地把 `play_url` / `pic` 里的老域名就地替换成新域名。
 * 三个调用方共用本类，保证「谁点的、怎么触发的」都一样：
 *   ① 站点机器通道 `api.php?route=replace`（token 鉴权）—— 给外部/脚本用；
 *   ② 后台「设置 → 地址替换」的「执行替换」按钮 —— **本地先执行**，再上报商店；
 *   ③ 后台「从商店拉取并应用」—— 逐条按商店记录调用。
 *
 * 为什么后台按钮不"HTTP 回环打自己的 api.php"：本站自己的地址多是内网/回环
 * （实测 192.0.2.100:8744），而全站出站闸 `outbound_url_safe()` 对私网/回环是**硬拦**的
 * （防 SSRF，红线不允许绕过）⇒ 回环请求会被自己的安全闸挡下。故后台与 api.php
 * **直接调用同一个服务方法**：代码路径完全相同，且不引入回环、DNS 依赖与双份超时预算。
 *
 * 安全口径（与接收链 `api.php?route=store_push` 逐字一致）：
 *   · 列名硬编码白名单（play → `play_url`，pic → `pic`），不接受外部传入列名；
 *   · 域名格式闸 `^[A-Za-z0-9.-]+(:\d+)?$`（只允许域名与可选端口）；
 *   · old === new 直接拒绝；全部走预编译绑定参数（REPLACE/LIKE 均绑定值）；
 *   · 每次调用 `set_time_limit()` 单独放宽执行时限（大表 REPLACE 可能很慢），
 *     上限 1800 秒、下限 5 秒，避免被拿来当"无限执行"入口。
 */
class DomainReplaceService
{
    /** 允许的 type → 列名（白名单，禁外部传列名） */
    const COLUMNS = array('play' => 'play_url', 'pic' => 'pic');

    /** 超时上下限（秒） */
    const TIMEOUT_MIN = 5;
    const TIMEOUT_MAX = 1800;
    const TIMEOUT_DEFAULT = 300;

    /**
     * 域名格式闸：只允许域名 + 可选端口（不含 scheme/路径/空格）
     */
    public static function validDomain($d): bool
    {
        return is_string($d) && '' !== $d && 1 === preg_match('#^[A-Za-z0-9.-]+(:\d+)?$#', $d);
    }

    /**
     * 归一化超时参数（秒）
     */
    public static function normalizeTimeout($t): int
    {
        $t = intval($t);
        if ($t <= 0) return self::TIMEOUT_DEFAULT;
        return max(self::TIMEOUT_MIN, min(self::TIMEOUT_MAX, $t));
    }

    /**
     * 执行一条替换
     *
     * @param array $p old_domain / new_domain / type(play|pic) / timeout(秒，选填)
     * @return array {code:0|−1, msg:string, updated:int, col:string, old:string, new:string, timeout:int}
     */
    public static function run(array $p): array
    {
        $type = (isset($p['type']) && 'pic' === $p['type']) ? 'pic' : 'play';
        $old = isset($p['old_domain']) ? trim((string)$p['old_domain']) : '';
        $new = isset($p['new_domain']) ? trim((string)$p['new_domain']) : '';
        $timeout = self::normalizeTimeout($p['timeout'] ?? 0);

        if (!self::validDomain($old) || !self::validDomain($new)) {
            return array('code' => -1, 'msg' => '域名格式非法（只允许字母数字点横线，可带端口；不含 http:// 与路径）',
                'updated' => 0, 'col' => '', 'old' => $old, 'new' => $new, 'timeout' => $timeout);
        }
        if ($old === $new) {
            return array('code' => -1, 'msg' => '新旧域名相同，无需替换',
                'updated' => 0, 'col' => '', 'old' => $old, 'new' => $new, 'timeout' => $timeout);
        }
        $db = _SERVER('db');
        if (!$db) {
            return array('code' => -1, 'msg' => '数据库不可用', 'updated' => 0, 'col' => '', 'old' => $old, 'new' => $new, 'timeout' => $timeout);
        }
        $col = self::COLUMNS[$type];
        $vod = $db->tablepre . 'vod';

        // 单次替换单独放宽执行时限：大站 play_url 是 TEXT，全表 REPLACE 可能跑很久
        if (function_exists('set_time_limit')) {
            @set_time_limit($timeout);
        }

        $stmt = $db->prepare_execute(
            "UPDATE `{$vod}` SET `{$col}` = REPLACE(`{$col}`, ?, ?) WHERE `{$col}` LIKE ?",
            array($old, $new, '%' . $old . '%')
        );
        if (!$stmt) {
            return array('code' => -1, 'msg' => 'SQL 执行失败（详见 runtime/log 的 sql 错误通道）',
                'updated' => 0, 'col' => $col, 'old' => $old, 'new' => $new, 'timeout' => $timeout);
        }
        $updated = intval($stmt->rowCount());
        if (function_exists('page_cache_bump')) {
            try { \page_cache_bump(); } catch (\Throwable $e) {}
        }
        return array('code' => 0, 'msg' => '替换完成', 'updated' => $updated,
            'col' => $col, 'old' => $old, 'new' => $new, 'timeout' => $timeout);
    }

    /**
     * 批量执行（供「从商店拉取并应用」用）：逐条调用 run()，累计改写行数
     *
     * @param array $rows 形如 [['type'=>'play','old_domain'=>'a','new_domain'=>'b'], …]
     * @return array {code,msg,rows,applied,changed,skipped,timeout}
     */
    public static function runBatch(array $rows, $timeout = 0): array
    {
        $timeout = self::normalizeTimeout($timeout);
        if (function_exists('set_time_limit')) {
            @set_time_limit($timeout);
        }
        $applied = 0;
        $changed = 0;
        $skipped = 0;
        foreach ($rows as $r) {
            if (!is_array($r)) { $skipped++; continue; }
            $one = self::run(array(
                'type'       => isset($r['type']) ? $r['type'] : 'play',
                'old_domain' => isset($r['old_domain']) ? $r['old_domain'] : '',
                'new_domain' => isset($r['new_domain']) ? $r['new_domain'] : '',
                'timeout'    => $timeout,
            ));
            if (0 === $one['code']) { $applied++; $changed += intval($one['updated']); }
            else { $skipped++; }
        }
        return array('code' => 0, 'msg' => '批量替换完成', 'rows' => count($rows),
            'applied' => $applied, 'changed' => $changed, 'skipped' => $skipped, 'timeout' => $timeout);
    }
}
