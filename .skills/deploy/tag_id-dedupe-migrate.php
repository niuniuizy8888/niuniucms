<?php
/**
 * tag_id 去重 + (tagid,vid) 唯一索引 一次性迁移脚本
 *
 * 背景（缺陷编号 #4，详见该问题对应的历史修复归档）：
 *   nncms_tag_id 没有 (tagid,vid) 唯一索引，而 tag_video_create 走不带主键的 REPLACE（等同 INSERT）
 *   ⇒ 存量库里同一 (tagid,vid) 可能攒出多行绑定；连带 tag.count 虚高（计数按行数累加）。
 *   代码侧已修（取全行、解绑范围收敛、按实际删掉的行数回补计数），本脚本负责**数据库侧**的收尾。
 *
 * 用法（务必先跑只读检查，确认无误再 --apply）：
 *   php .skills/deploy/tag_id-dedupe-migrate.php                 # 只读体检：报告重复/孤儿/索引现状
 *   php .skills/deploy/tag_id-dedupe-migrate.php --print-sql     # 不连库，打印将要执行的 SQL 形态（离线可审阅）
 *   php .skills/deploy/tag_id-dedupe-migrate.php --apply         # 执行：备份表 → 去重 → 加唯一索引
 *   php .skills/deploy/tag_id-dedupe-migrate.php --apply --recount   # 追加按实际行数重算 tag.count
 *   php .skills/deploy/tag_id-dedupe-migrate.php --apply --yes    # 跳过交互确认（cron 里慎用）
 *
 * 安全设计：
 *   1. 默认**只读**；--apply 才写；--apply 且没 --yes 时要求手输 yes 确认。
 *   2. 幂等：已存在 uk_tagid_vid 索引则直接报告并退出（不会重复 ALTER）。
 *   3. 写之前把整表备份成 tag_id_bak_<YmdHis>，并在结束时打印回滚 SQL。
 *   4. 去重不用「同表派生子查询 DELETE」（各 MySQL/MariaDB 版本对方差允许性不一致），
 *      而是先把要删的 id 查进 PHP，再按 500 个一批 DELETE … WHERE id IN (…)，
 *      每一条实际执行的语句都会打印出来，可审计、可中断。
 *   5. 保留每组 (tagid,vid) 中 **id 最小**的那行（与"最早写入的绑定"一致）。
 *   6. 不动 sql_mode（严格模式是项目红线，见 CLAUDE.md §三.3）。
 *      新装站点已由 core/install/install.sql 直接带 UNIQUE(tagid,vid)（索引轮落地），
 *      **本脚本只服务存量库**：那里有历史重复行，无法直接 ALTER，必须先查重再删再加索引。
 *
 * 只在 CLI 下运行（Web 访问 .skills/ 已被 nginx 目录级 404 挡住，这里再兜一层）。
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('This script is CLI-only.' . PHP_EOL);
}
if (version_compare(PHP_VERSION, '8.0', '<')) {
    exit('需要 PHP 8.0 或更高版本（当前 ' . PHP_VERSION . '）。' . PHP_EOL);
}

/* ============================== 参数 ============================== */
$APPLY   = in_array('--apply', $argv, true);
$RECOUNT = in_array('--recount', $argv, true);
$PRINT   = in_array('--print-sql', $argv, true);
$YES     = in_array('--yes', $argv, true);

/* ============================== 配置装载（与 index.php + core/core.php 同口径） ============================== */
$appPath = dirname(__DIR__, 2) . '/';           // 站点根（本文件在 .skills/deploy/ 下）
$configFile = $appPath . 'core/config/config.php';
if ($PRINT) {
    // 离线审阅模式不需要配置，直接打印 SQL 形态
    print_sql_templates('<前缀>');
    exit(0);
}
if (!is_file($configFile)) {
    exit("找不到 {$configFile} —— 站点尚未安装，或请从站点根目录运行本脚本。\n");
}
$conf = include $configFile;
if (!is_array($conf) || empty($conf['db'])) {
    exit("config.php 里没有 db 配置段。\n");
}
// 独立数据库配置存在则整体覆盖（与 core/core.php 的处理一致）
$dbConfigFile = $appPath . 'core/config/database.php';
if (is_file($dbConfigFile)) {
    $dbConf = include $dbConfigFile;
    if (is_array($dbConf) && !empty($dbConf['master'])) {
        $conf['db'] = $dbConf;
    }
}
$dbType = $conf['db']['type'] ?? 'mysql';
// type 可能是 proxy/pdo/pdo_mysql/mysql —— 归一到实际存有主库节点的键
$nodeKeys = array('pdo_mysql', 'mysql');
$master = null;
foreach (array_merge(array($dbType), $nodeKeys) as $k) {
    if (!empty($conf['db'][$k]['master'])) { $master = $conf['db'][$k]['master']; break; }
}
if (!$master && !empty($conf['db']['master'])) { $master = $conf['db']['master']; }
if (!$master) {
    exit("配置里找不到 db master 节点（检查 conf['db']['{$dbType}']['master']）。\n");
}
$pre = (string)($master['tablepre'] ?? '');
// 表名直接拼进反引号，所以先做标识符校验（等价于 QueryBuilder::qi() 的白名单口径）
if ($pre !== '' && !preg_match('/^[A-Za-z0-9_]+$/', $pre)) {
    exit("表前缀不合法（仅允许字母/数字/下划线）：{$pre}\n");
}
$T_TAG  = '`' . $pre . 'tag' . '`';
$T_LINK = '`' . $pre . 'tag_id' . '`';
$UK     = 'uk_tagid_vid';

/* ============================== 连接 ============================== */
$host = (string)($master['host'] ?? '127.0.0.1');
$port = (int)($master['port'] ?? 3306);
$name = (string)($master['name'] ?? '');
$user = (string)($master['user'] ?? '');
$pass = (string)($master['password'] ?? '');
try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ));
} catch (PDOException $e) {
    exit('数据库连接失败：' . $e->getMessage() . "（host={$host} port={$port} db={$name}）\n");
}
echo "已连接 {$host}:{$port}/{$name}  表前缀='" . $pre . "'  目标表 {$T_LINK}\n";

function q(PDO $pdo, string $sql, array $bind = array()): array {
    $st = $pdo->prepare($sql);
    $st->execute($bind);
    return $st->fetchAll();
}

/* ============================== 1) 现状体检（只读） ============================== */
$tableExists = (bool) q($pdo, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", array($pre . 'tag_id'));
if (!$tableExists) { exit("表 {$pre}tag_id 不存在，无需迁移。\n"); }

$indexes = q($pdo, 'SHOW INDEX FROM ' . $T_LINK);
$hasUk = false;
foreach ($indexes as $ix) {
    if (($ix['Key_name'] ?? '') === $UK) { $hasUk = true; }
}
$totalRows = (int)(q($pdo, "SELECT COUNT(*) AS n FROM {$T_LINK}")[0]['n'] ?? 0);
$dupGroups = (int)(q($pdo, "SELECT COUNT(*) AS n FROM (SELECT 1 AS x FROM {$T_LINK} GROUP BY tagid, vid HAVING COUNT(*) > 1) d")[0]['n'] ?? 0);
$dupRows   = (int)(q($pdo, "SELECT COALESCE(SUM(c),0) AS n FROM (SELECT COUNT(*) AS c FROM {$T_LINK} GROUP BY tagid, vid HAVING COUNT(*) > 1) d")[0]['n'] ?? 0)
           - $dupGroups;   // 每组保留 1 行 ⇒ 待删 = 重复组总行数 - 组数
$orphanTag = (int)(q($pdo, "SELECT COUNT(*) AS n FROM {$T_LINK} r LEFT JOIN {$T_TAG} t ON t.tagid = r.tagid WHERE t.tagid IS NULL")[0]['n'] ?? 0);

echo "\n== 现状 ==\n";
printf("  绑定行总数            : %d\n", $totalRows);
printf("  重复 (tagid,vid) 组数 : %d\n", $dupGroups);
printf("  待删除的冗余行数      : %d\n", max(0, $dupRows));
printf("  指向已不存在标签的孤儿: %d   %s\n", $orphanTag, $orphanTag ? '（本脚本只报告，不删——见文末建议）' : '');
printf("  唯一索引 %s 是否已存在: %s\n", $UK, $hasUk ? '是' : '否');

if ($dupGroups > 0) {
    echo "\n== 重复样本（最多 10 组，保留 id 最小行）==\n";
    $samples = q($pdo, "SELECT tagid, vid, COUNT(*) AS c, GROUP_CONCAT(id ORDER BY id) AS ids FROM {$T_LINK} GROUP BY tagid, vid HAVING c > 1 ORDER BY c DESC LIMIT 10");
    foreach ($samples as $s) {
        printf("  tagid=%-8s vid=%-10s 行数=%-3s ids=%s\n", $s['tagid'], $s['vid'], $s['c'], $s['ids']);
    }
}

/* ============================== 2) 幂等早退 ============================== */
if ($hasUk) {
    echo "\n索引 {$UK} 已存在 ⇒ 迁移已完成过，本脚本不做任何修改，直接退出。\n";
    if ($dupGroups > 0) { echo "⚠ 但仍有重复组，说明索引是被绕过建的（不应发生）——请人工核查。\n"; }
    exit(0);
}

/* ============================== 3) 确认 ============================== */
if (!$APPLY) {
    echo "\n这是**只读体检**（未做任何修改）。确认上面数字合理后，加 --apply 执行：\n";
    echo "  php .skills/deploy/tag_id-dedupe-migrate.php --apply --recount\n";
    exit(0);
}
print_sql_templates($pre);
if (!$YES) {
    echo "\n将执行：备份 {$pre}tag_id → 删除上述冗余行 → ALTER 加唯一索引" . ($RECOUNT ? ' → 重算 tag.count' : '') . "\n";
    echo "输入 yes 继续，其它任意输入取消：";
    $in = trim((string)fgets(STDIN));
    if (strtolower($in) !== 'yes') { exit("已取消（未做任何修改）。\n"); }
}

/* ============================== 4) 备份 ============================== */
$bak = $pre . 'tag_id_bak_' . date('YmdHis');
if (!preg_match('/^[A-Za-z0-9_]+$/', $bak)) { exit("备份表名不合法：{$bak}\n"); }
$pdo->exec('CREATE TABLE `' . $bak . '` LIKE ' . $T_LINK);
$pdo->exec('INSERT INTO `' . $bak . '` SELECT * FROM ' . $T_LINK);
$bakRows = (int)(q($pdo, "SELECT COUNT(*) AS n FROM `{$bak}`")[0]['n'] ?? 0);
echo "\n[1/4] 已备份整表到 `{$bak}`（{$bakRows} 行，与原表 {$totalRows} 行一致）\n";

/* ============================== 5) 去重（先一次查出待删 id，再分批删） ============================== */
// 单条查询取全部待删 id（不在循环里逐组查——那会是 N+1，违反 CLAUDE.md §三.2）
$deleteIds = array();
$rows = q($pdo, "SELECT r.id FROM {$T_LINK} r"
    . " JOIN (SELECT tagid, vid, MIN(id) AS keep_id FROM {$T_LINK} GROUP BY tagid, vid HAVING COUNT(*) > 1) d"
    . " ON r.tagid = d.tagid AND r.vid = d.vid WHERE r.id <> d.keep_id");
foreach ($rows as $x) { $deleteIds[] = (int)$x['id']; }
$deleteIds = array_values(array_unique($deleteIds));
sort($deleteIds);
$removed = 0;
if ($deleteIds) {
    foreach (array_chunk($deleteIds, 500) as $chunk) {   // 批量上限 500，与 admin_param_ids 的口径一致
        $in = implode(',', $chunk);                       // 全部来自 (int) 转换，无注入面
        $st = $pdo->prepare("DELETE FROM {$T_LINK} WHERE id IN ({$in})");
        $st->execute();
        $removed += $st->rowCount();
        echo "      DELETE FROM {$T_LINK} WHERE id IN (" . substr($in, 0, 60) . (strlen($in) > 60 ? '…' : '') . ")  → " . $st->rowCount() . " 行\n";
    }
}
echo "[2/4] 去重完成：删除 {$removed} 行（预期 " . count($deleteIds) . " 行）\n";

/* ============================== 6) 复核 + 加索引 ============================== */
$dupAfter = (int)(q($pdo, "SELECT COUNT(*) AS n FROM (SELECT 1 AS x FROM {$T_LINK} GROUP BY tagid, vid HAVING COUNT(*) > 1) d")[0]['n'] ?? 0);
if ($dupAfter > 0) {
    exit("\n⚠ 复核发现仍有 {$dupAfter} 组重复（可能有并发写入正在产生新绑定）。已中止，**未加索引**。\n"
        . "  备份表 `{$bak}` 保留可回滚。请停止采集/批量改写后重跑本脚本。\n");
}
$pdo->exec("ALTER TABLE {$T_LINK} ADD UNIQUE INDEX `{$UK}` (`tagid`, `vid`)");
echo "[3/4] 已加唯一索引 {$UK}(tagid, vid)\n";

/* ============================== 7) 可选：按实际行数重算 tag.count ============================== */
if ($RECOUNT) {
    $before = q($pdo, "SELECT COUNT(*) AS s FROM {$T_TAG} WHERE count <> (SELECT COUNT(*) FROM {$T_LINK} r WHERE r.tagid = {$T_TAG}.tagid)");
    $mismatch = (int)($before[0]['s'] ?? 0);
    $pdo->exec("UPDATE {$T_TAG} t SET t.count = (SELECT COUNT(*) FROM {$T_LINK} r WHERE r.tagid = t.tagid) WHERE t.count <> (SELECT COUNT(*) FROM {$T_LINK} r WHERE r.tagid = t.tagid)");
    echo "[4/4] 已按实际绑定行数重算 tag.count（修正 {$mismatch} 个标签的计数）\n";
} else {
    echo "[4/4] 未做 tag.count 重算（如需，重跑时加 --recount；注意本脚本不重复备份，因为已无重复行）\n";
}

/* ============================== 8) 收尾报告 ============================== */
$finalRows = (int)(q($pdo, "SELECT COUNT(*) AS n FROM {$T_LINK}")[0]['n'] ?? 0);
echo "\n== 结果 ==\n";
printf("  绑定行数 %d → %d（删 %d）\n", $totalRows, $finalRows, $removed);
printf("  重复组数 %d → %d\n", $dupGroups, $dupAfter);
echo "  唯一索引：" . (($chk = q($pdo, 'SHOW INDEX FROM ' . $T_LINK)) && array_filter($chk, function($x) use ($UK) { return ($x['Key_name'] ?? '') === $UK; }) ? " {$UK} 已生效" : ' ⚠ 未确认') . "\n";
if ($orphanTag > 0) {
    echo "\n  仍有 {$orphanTag} 行指向已不存在的标签（本脚本没动）。确认后可手工清理：\n";
    echo "    DELETE r FROM {$T_LINK} r LEFT JOIN {$T_TAG} t ON t.tagid = r.tagid WHERE t.tagid IS NULL;\n";
}
echo "\n回滚路径（如需）：\n";
echo "  ALTER TABLE {$T_LINK} DROP INDEX `{$UK}`;\n";
echo "  TRUNCATE TABLE {$T_LINK}; INSERT INTO {$T_LINK} SELECT * FROM `{$bak}`;\n";
echo "  确认无误后可删备份： DROP TABLE `{$bak}`;\n\n";
echo "提示：新装站点已自带该唯一索引（core/install/install.sql）；跑完本脚本后，本站点与新装站点口径一致。\n";
echo "      配套代码改动已在库内：TagFlowService::process() 插入前预过滤 + tag_video_big_insert 默认 INSERT IGNORE。\n";

/**
 * 打印本脚本会执行的 SQL 形态（离线审阅用，不连库）。
 * @param string $pre 表前缀
 * @return void
 */
function print_sql_templates(string $pre): void
{
    $link = '`' . $pre . 'tag_id`';
    $tag  = '`' . $pre . 'tag`';
    echo "\n== 本脚本将执行的 SQL 形态 ==\n";
    echo "-- 只读体检\n";
    echo "  SELECT tagid, vid, COUNT(*) c, GROUP_CONCAT(id ORDER BY id) ids FROM {$link} GROUP BY tagid, vid HAVING c > 1;\n";
    echo "  SELECT COUNT(*) FROM {$link} r LEFT JOIN {$tag} t ON t.tagid = r.tagid WHERE t.tagid IS NULL;  -- 孤儿，仅报告\n";
    echo "-- 备份\n";
    echo "  CREATE TABLE `{$pre}tag_id_bak_<YmdHis>` LIKE {$link};\n";
    echo "  INSERT INTO `{$pre}tag_id_bak_<YmdHis>` SELECT * FROM {$link};\n";
    echo "-- 去重（每组保留 MIN(id)；先查 id 再分批，每批 ≤500）\n";
    echo "  SELECT r.id FROM {$link} r JOIN (SELECT tagid, vid, MIN(id) AS keep_id FROM {$link} GROUP BY tagid, vid HAVING COUNT(*) > 1) d\n";
    echo "    ON r.tagid = d.tagid AND r.vid = d.vid WHERE r.id <> d.keep_id;\n";
    echo "  DELETE FROM {$link} WHERE id IN (…);\n";
    echo "-- 加索引（先复核重复组=0，否则中止）\n";
    echo "  ALTER TABLE {$link} ADD UNIQUE INDEX `uk_tagid_vid` (`tagid`, `vid`);\n";
    echo "-- 可选重算计数（--recount）\n";
    echo "  UPDATE {$tag} t SET t.count = (SELECT COUNT(*) FROM {$link} r WHERE r.tagid = t.tagid)\n";
    echo "    WHERE t.count <> (SELECT COUNT(*) FROM {$link} r WHERE r.tagid = t.tagid);\n";
}
