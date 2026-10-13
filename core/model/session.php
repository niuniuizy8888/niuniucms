<?php
/**
 * @deprecated 兼容壳 — 会话函数全部转发到 \App\Service\Account\SessionService（及 Handler/*），
 * 新代码禁止调用（§二.2），请直接用 SessionService；下方保留的架构说明仅为维护参考。
 */
/*
	php 默认的 session 采用文件存储，并且使用 flock() 文件锁避免并发访问不出问题（实际上还是无法解决业务层的并发读后再写入）
	自定义的 session 存储（MySQL nncms_session / Redis）同样没有跨请求的行锁，同一 sid 的并发写覆盖风险客观存在。
	缓解在服务端而非前端：前端（static/js/app.js）并没有请求串行化机制。
	\App\Service\Account\SessionService::write() 写前重读当前行并按 key 合并（本请求修改的键以本请求为准，
	其余键保留库内现值）；\App\Service\Account\Handler\DualSessionHandler::write() 已登录分支同口径键级合并。

	=== Session 架构（以 SessionService::start() 实际装配为准）===
	登录/匿名双存储：redis 扩展在位且 self::redis() 可用 ⇒ new Handler\DualSessionHandler()
	  （登录会话落 MySQL nncms_session、匿名会话落 Redis）；否则 ⇒ new Handler\BullSessionHandler()（纯 MySQL）。
	自定义处理器抛错时，回退 PHP 内置 \SessionHandler（文件存储）。
	✅ Handler\RedisBullSessionHandler（历史 Redis-only 形态，被 DualSessionHandler 取代）已在
	  「死代码清理轮」核验后删除：全仓 0 实例化、无动态实例化、无继承关系、无插件 hook 指向它；
	  原文件可从 /root/cleanup-deadfiles-* 快照恢复。本节旧文案「优先使用它、降级用 BullSessionHandler」
	  与实现不符，已一并更正。

	- 会话存储：Redis（DB 号由 config 的 multi.cache redis 层决定）+ MySQL 双写
	- 持久连接：pconnect + persistent_id 减少 TCP 握手
	- 自动过期：Redis TTL（online_hold_time），MySQL 侧仍需 GC 扫描
	- 在线统计：SessionService::onlineCount()/onlineListCache()/onlineUserListCache()
	  —— Redis SCAN（+ mget 批量反序列化）与 MySQL 侧 uid>0 的计数是**相加**关系（双存储各计一份），
	  不是「Redis 优先、MySQL 降级」；且 onlineCount() 返回值 max(1, ·) 恒 ≥1（零访客也显示 1）。
*/
// hook model_session_start.php
$sid = '';
$g_session = array();
$g_session_invalid = FALSE; // 0: 有效， 1：无效
// 可以指定独立的 session 服务器，在系统压力巨大的时候可以考虑优化
//$g_sess_db = $db;
// 如果是管理员, sid, 与 ip 绑定，一旦 IP 发生变化，则需要重新登录。管理员采用 token (绑定IP) 双重验证，避免 sid 被中间窃取。
// hook model_session_before.php

// ==================== 兼容壳（CRUD） ====================
// @deprecated 底层 CRUD 转发到 RepositoryFactory，新代码应直接使用 RepositoryFactory

/** @deprecated 兼容壳：转发 RepositoryFactory::table('session','sid')->update(array('sid' => $sid), $update, $d)（QueryBuilder 真·预编译）。⚠ 这是绕过 SessionService::putRow() 的裸写路径——没有列宽截断、没有回读确认、没有失败留痕；url/useragent 一旦超出真实列宽（install.sql 现为 varchar(255)；存量未跑列宽迁移的站点仍是 url char(32)/useragent char(128)）严格模式就报 1406，而 PdoDriver::error() 把它吞成 false ⇒ 主行静默写不进去，这正是 §七 排障表「session 主行写不进 = 严格模式 1406」的历史根因；SessionService::putRow() 的截断+回读确认+session_write_error 留痕就是为堵这条路径，本函数没有这些保护。会话主行写入请用 SessionService，新代码不要再调本函数。@param string $sid 会话 ID（char(32)） @param array $update 列=>值（列名 +/− 后缀=自增表达式） @param \PdoDriver|null $d 连接选择器 @return int|false 影响行数；空 cond/空 update 为 FALSE */
/** @deprecated 兼容壳：转发 RepositoryFactory::table('session','sid')->findOne(array('sid' => $sid), $orderby, $col, $d)。只取 nncms_session 主行，bigdata=1 的行不会去 session_data 展开 data（那是 SessionService::read 的职责），拿到的是原始存储行、不含格式化。@param string $sid 会话 ID @param array $orderby db_find 风格 列=>1/-1 @param array $col 显式列清单；空 ⇒ SELECT * @param \PdoDriver|null $d 连接选择器 @return array|null 主行数据；无记录 NULL（QueryBuilder::first 空结果统一给 null） */
function session_read($sid, $orderby = array(), $col = array(), $d = NULL)
{
    // hook model_session_session_read_start.php

    $r = \App\Repository\RepositoryFactory::table('session', 'sid')->findOne(array('sid' => $sid), $orderby, $col, $d);
    // hook model_session_session_read_end.php
    return $r;
}
/** @deprecated 兼容壳：转发 RepositoryFactory::table('session','sid')->delete($cond, $d)。只删 nncms_session 主行，不动 session_data（会留下 data 孤儿行，两表同删见 SessionService::destroy）。@param array $cond applyCond 口径（数组值=IN、['<' => x]=比较符）；空数组直接 FALSE（QueryBuilder 对无 where 的 delete 抛异常，防全表误写） @param \PdoDriver|null $d 连接选择器 @return int|false 影响行数；空 cond 为 FALSE */
function session_delete($cond = array(), $d = NULL)
{
    // hook model_session_session_delete_start.php

    $r = \App\Repository\RepositoryFactory::table('session', 'sid')->delete($cond, $d);
    // hook model_session_session_delete_end.php
    return $r;
}
/** @deprecated 兼容壳：转发 RepositoryFactory::table('session','sid')->find(...)，DbTableRepository::find 语义（$key 非空则按其列值重键）。在线统计已改走 SessionService::onlineCount()/onlineListCache()（Redis SCAN + mget，MySQL 兜底），新代码不要再调本函数。@param array $cond @param array $orderby 列=>1/-1 @param int $page 页码（1 起） @param int $pagesize 每页条数 @param string $key 结果重键列；空=顺序索引 @param array $col 显式列清单；空 ⇒ SELECT *（DEBUG>0 时才记 sql_star_select） @param \PdoDriver|null $d 连接选择器 @return array 行列表；无结果为空数组 */
/** @deprecated 兼容壳：转发 RepositoryFactory::table('session','sid')->count($cond, $d)。只统计 nncms_session 主行；在线人数请用 SessionService::onlineCount()（含 Redis 侧会话）。@param array $cond 空数组=全表计数 @param \PdoDriver|null $d 连接选择器 @return int DbTableRepository::count 声明 : int */
/** @deprecated 兼容壳：转发 RepositoryFactory::table('session_data','sid')->update(array('sid' => $sid), $update, $d)。裸 UPDATE，不经 SessionService::putData() 的「diff 先行 + 整行 REPLACE + 回读确认 + 失败留痕」三级策略。@param string $sid 会话 ID @param array $update 列=>值 @param \PdoDriver|null $d 连接选择器 @return int|false 影响行数；空参 FALSE */
/** @deprecated 兼容壳：转发 RepositoryFactory::table('session_data','sid')->findOne(array('sid' => $sid), $orderby, $col, $d)。@param string $sid 会话 ID @param array $orderby db_find 风格 @param array $col 显式列清单；空 ⇒ SELECT * @param \PdoDriver|null $d 连接选择器 @return array|null data 行；无记录 NULL。⚠ 老库升级行 data 列可能为 NULL，消费前要 (string) 强转（SessionService::read 即如此处理，Handler::read 要求 string 返回） */
function session_data_read($sid, $orderby = array(), $col = array(), $d = NULL)
{
    // hook model_session_session_data_read_start.php

    $r = \App\Repository\RepositoryFactory::table('session_data', 'sid')->findOne(array('sid' => $sid), $orderby, $col, $d);
    // hook model_session_session_data_read_end.php
    return $r;
}
/** @deprecated 兼容壳：转发 RepositoryFactory::table('session_data','sid')->delete($cond, $d)。只清超大数据行，不碰主行 ⇒ 单独调用会把会话留在「主行 bigdata=1 但无 data 行」的空数据态。@param array $cond 空数组直接 FALSE（防无 where 全表删） @param \PdoDriver|null $d 连接选择器 @return int|false 影响行数；空 cond FALSE */
function session_data_delete($cond = array(), $d = NULL)
{
    // hook model_session_session_data_delete_start.php

    $r = \App\Repository\RepositoryFactory::table('session_data', 'sid')->delete($cond, $d);
    // hook model_session_session_data_delete_end.php
    return $r;
}

// ==================== 兼容壳（Session Handler） ====================
// @deprecated 业务函数转发到 SessionService，新代码应直接使用 SessionService

function sess_open($save_path, $session_name)
{
    // hook model_session_sess_open_start.php

    // hook model_session_sess_open_end.php
    return \App\Service\Account\SessionService::open($save_path, $session_name);
}
/** @deprecated 兼容壳：转发 SessionService::close()——该方法现恒返回 true（自定义 Handler 的接口占位，无资源要释放）。被 BullSessionHandler::close() 用作 : bool 返回的实现体，属会话处理器回调链，不建议新代码直接调过程函数。@return bool 恒 TRUE */
function sess_close()
{
    // hook model_session_sess_close_start.php

    // hook model_session_sess_close_end.php
    return \App\Service\Account\SessionService::close();
}
/** @deprecated 兼容壳：转发 SessionService::read($sid)，会话处理器的读取回调。行为要点：$sid 为空或主行读不到时**就地 newSession() 新建会话并返回空串**（不是报错，表现为「每次请求都像新会话」）；主行 bigdata=1 时再去 session_data 表展开 data。@param string $sid 会话 ID @return string 序列化后的会话原文；无内容/新建会话为空字符串 */
function sess_read($sid)
{
    // hook model_session_sess_read_start.php

    // hook model_session_sess_read_end.php
    return \App\Service\Account\SessionService::read($sid);
}
/** @deprecated 兼容壳：转发 SessionService::newSession($sid)。职责是「新会话落地」：无 cookie_test 时只下发 cookie_test（AES-256-GCM 加密的 md5(UA+longip) 指纹）并直接返回**不写库**；已带 cookie_test 时经 hash_equals 恒时校验，不符则置 $g_session_invalid=TRUE（后续判「非法操作」的依据之一），随后把 url/useragent 原样交给 SessionService::putRow() 写主行——超长截断与 1406 留痕都在 putRow 里，不在本壳。@param string $sid 会话 ID @return NULL 底层无返回值，调用方不得消费 */
/** @deprecated 兼容壳：转发 SessionService::write($sid, $data)，会话处理器的写入回调。要点：$data 只当**真值开关**用——为真时实际写入内容取自 session_encode()（当前 $_SESSION），不是传进来的字符串；会话未激活则退化成空串。落库经 putRow()/putData()（按真实列宽截断 + 失败重试 + session_write_error 留痕），带写入前重读按键合并（防并发覆盖 safe_token 场景键）、P-9 延迟更新短路、匿名无数据不建行三档优化。@param string $sid 会话 ID @param mixed $data 真值=按当前会话编码写入；空值=只更新主行元数据 @return bool 恒 TRUE（写失败以日志留痕表达，不上抛，调用方不能据此判定落库成功） */
function sess_write($sid, $data)
{
    // hook model_session_sess_write_start.php

    // hook model_session_sess_write_end.php
    return \App\Service\Account\SessionService::write($sid, $data);
}
/** @deprecated 兼容壳：转发 SessionService::destroy($sid)——同删 nncms_session 主行与 session_data 行（两表一起清，不留孤儿 data）。@param string $sid 会话 ID @return bool 恒 TRUE（不看底层 rowCount，删除失败不回吐） */
function sess_destroy($sid)
{
    // hook model_session_sess_destroy_start.php

    // hook model_session_sess_destroy_end.php
    return \App\Service\Account\SessionService::destroy($sid);
}
/** @deprecated 兼容壳：转发 SessionService::gc($maxlifetime)，PHP 概率触发的会话回收（gc_probability/gc_divisor=1/1000）。一次最多扫 10000 条 last_date < time-$maxlifetime 的主行，**保留** 6 小时内活跃的 bigdata 行（保护正在上传的附件），其余同删 session 与 session_data 两表。Redis 层靠 TTL 自动过期，这里是 MySQL 侧兜底。@param int $maxlifetime 存活秒数（取 conf['online_hold_time']） @return bool 恒 TRUE。BullSessionHandler::gc 会把非 int 返回值折成 0 以满足接口 : int 声明 */
function sess_gc($maxlifetime)
{
    // hook model_session_sess_gc_start.php

    // hook model_session_sess_gc_end.php
    return \App\Service\Account\SessionService::gc($maxlifetime);
}

// ==================== 兼容壳（Redis 连接） ====================
// @deprecated Redis 连接逻辑已迁入 SessionService::redisConfig() / SessionService::redis()

/** @deprecated 改用 \App\Service\Account\SessionService::redisConfig() */
/** @deprecated 改用 \App\Service\Account\SessionService::redis() */
function _sess_redis(): ?Redis
{
    // hook model_session__sess_redis_start.php

    // hook model_session__sess_redis_end.php
    return \App\Service\Account\SessionService::redis();
}

// ==================== 兼容壳（Session 管理） ====================
// @deprecated 管理函数转发到 SessionService

function sess_start()
{
    // hook model_session_sess_start_start.php

    // hook model_session_sess_start_end.php
    return \App\Service\Account\SessionService::start();
}

/** @deprecated 兼容壳：转发 SessionService::clearAttach()——用 global $sid 把当前会话主行的 bigdata 复位为 0 并删除 session_data 行（附件/超大上传会话用后即焚）。主行不存在或本就非 bigdata 时不动。@return bool 恒 TRUE */
/** @deprecated 兼容壳：转发 SessionService::onlineCount()——Redis SCAN 出的 sess_* 会话数 + MySQL 侧 uid>0 的登录主行数相加，返回 max(1, 合计)（**下限 1，永不返回 0**）。不做分页缓存，每次调用都真扫。@return int 在线数（含匿名 Redis 会话） */
function online_count()
{
    // hook model_session_online_count_start.php

    // hook model_session_online_count_end.php
    return \App\Service\Account\SessionService::onlineCount();
}

/** @deprecated 兼容壳：转发 SessionService::onlineListCache()——在线用户明细列表（字段 uid/username/gid/ip_fmt/last_date_fmt/url/useragent），来源为 Redis SCAN 登录会话 + MySQL uid>0 主行按 uid 去重合并（MySQL 取最多 1000 行）。请求内 static memo + 300s 分布式缓存（键 online_list）；只收登录用户，匿名不计入。@return array 明细行列表（顺序索引）；无在线用户为空数组 */
/** @deprecated 兼容壳：转发 SessionService::onlineUserListCache()——在线用户 UID 集合（以 uid 为键、值仅含 uid），用于「某人是否在线」判定，与 online_list_cache 同取数口径（Redis 优先、MySQL uid>0 兜底、只含登录用户），各自独立缓存键（online_user_list，TTL 300s）。@return array uid 为键的集合；无在线用户为空数组 */
function online_user_list_cache()
{
    // hook model_session_online_user_list_cache_start.php

    // hook model_session_online_user_list_cache_end.php
    return \App\Service\Account\SessionService::onlineUserListCache();
}
