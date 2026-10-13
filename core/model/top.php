<?php
/**
 * @deprecated 兼容壳 — 全部函数转发到 \App\Service\Content\VideoService / ArtService
 * 新代码禁止调用，应直接使用 VideoService 或 ArtService。
 */
// hook model_top_start.php
$tablepre = ($GLOBALS['db'] ?? null) ? $GLOBALS['db']->tablepre : 'nncms_';
/**
 * @deprecated 兼容壳（CLAUDE.md「目录规则」）：转发全局 ip()（core/func/ip.php，
 * CDN 模式下仅当直连 IP 在受信代理白名单才采信转发头）。新代码直接调 ip()。
 * @return string|NULL 客户端 IP 文本（v4/v6，取 REMOTE_ADDR 或经受信代理白名单校验的 CDN 转发头；
 *                     CLI 等无 REMOTE_ADDR 场景为 NULL）
 */
/**
 * @deprecated 兼容壳：转发 VideoService::recordTopView（ContentRankTrait）——
 * 向 vod_top 记一条浏览明细（vid+cid+时间）。同 (vid, ip) 60s 内经缓存去重不再记录。
 * 新代码用 VideoService::recordTopView。
 * @param int $vid
 * @param int $cid
 * @return int|false 明细行自增 ID；id 非法或命中 60s 去重=FALSE
 */
/**
 * @deprecated 兼容壳：转发 ArtService::recordTopView，语义同 vod_top_view（aid 口径）。
 * 新代码用 ArtService::recordTopView。
 * @param int $aid
 * @param int $cid
 * @return int|false 明细行自增 ID；id 非法或命中 60s IP 去重=FALSE
 */
/**
 * @deprecated 兼容壳：转发 VideoService::hitRows——按时间窗聚合 vod_top 得热门计数行。
 * 行形态是 {vid, cnt}（id+窗内点击数），**不是完整视频行**，需回表请走 VideoService::find。
 * 新代码用 VideoService::hitRows。
 * @param string $type 'd'/'w'/'m'（归一为 hits_day/week/month）或完整 hits_* 名
 * @param int $limit 取前 N（内部 intval）
 * @return array 计数行列表（无数据=空数组）
 */
/**
 * @deprecated 兼容壳：转发 ArtService::hitRows，行形态 {aid, cnt}，口径同 vod_get。
 * 新代码用 ArtService::hitRows。
 * @param string $type 'd'/'w'/'m' 或完整 hits_* 名
 * @param int $limit
 * @return array 计数行列表
 */
/**
 * @deprecated 兼容壳：转发 ArtService::hitRows($type, $limit, $cid)——限定分类的热门计数行。
 * 行形态 {aid, cnt}，口径同 art_get。⚠ $cid=0 是「按 cid=0 过滤」而不是「不限分类」：
 * 「不限」只在底层 hitRows 缺省 $cid（NULL）时成立，本壳默认值 0 给不到；
 * cid=0 行可真实存在（后台允许未分类内容），命中与否取决于库内数据。新代码用 ArtService::hitRows。
 * @param string $type 'd'/'w'/'m' 或完整 hits_* 名
 * @param int $cid 限定分类（0=按 cid=0 查询；数组在底层转 IN）
 * @param int $limit
 * @return array 计数行列表
 */
/**
 * @deprecated 兼容壳：转发 VideoService::hitRows($type, $limit, $cid)——限定分类的热门计数行。
 * 行形态 {vid, cnt}，口径同 vod_get；$cid=0 的「按 cid=0 过滤≠不限分类」同 art_get_cid。
 * 新代码用 VideoService::hitRows。
 * @param string $type 'd'/'w'/'m' 或完整 hits_* 名
 * @param int|array $cid 限定分类
 * @param int $limit
 * @return array 计数行列表
 */
/**
 * @deprecated 兼容壳（文件头禁令：全部函数转发 VideoService/ArtService，新代码禁止调用）：
 * 转发 VideoService::hitPage($type, $cid, $page, $pagesize)（原注释「可翻页的分类热门视频」）。
 * 取数路径与 hitRows 同：从排行流水表按时间窗聚合出 top-N 内容 id，只是**多带 total 的标准分页结构**。
 * ⚠ 返回的 list 是**聚合明细行 {vid, cnt}，不是视频整行** ⇒ 渲染必须再回表
 *   （VideoService::find/findByIdsOrdered 一类），别直接把明细行丢给模板取 title/pic。
 * ⚠ 「不限分类」要传 NULL 或空串，**传 0 会被当成 cid=0 过滤**（与 art_hit_cid 同口径）。
 * @param string $type 窗口类型：'d'/'w'/'m' 短写或完整 hits_* 名（默认 'hits_day'，底层 normalizeHitType 归一，未知值回落 'hits'）
 * @param mixed $cid 分类过滤（NULL/'' = 全站；int/array = 限定）
 * @param int $page 页码，1 起（壳内 intval）
 * @param int $pagesize 每页条数（壳内 intval）
 * @return array ['list' => 排行明细行数组(内容 id + cnt), 'total' => int 窗口内明细总数, 'page' => int, 'pagesize' => int]
 */
/**
 * @deprecated 兼容壳：转发 ArtService::hitPage——分类内时间窗热门分页。
 * 注意 $type 默认 'hits_day'（完整名，'d' 缩写亦可）；底层经 normalizeHitType 归一。
 * 返回的 list 是聚合计数行 {aid, cnt}（**非文章整行**），渲染需再回表。
 * 「不限分类」应传 NULL 而非 0（0 会按 cid=0 过滤）。新代码用 ArtService::hitPage。
 * @param string $type 'd'/'w'/'m' 或完整 hits_* 名
 * @param int|array $cid 限定分类
 * @param int $page 1 起（内部 intval）
 * @param int $pagesize 内部 intval
 * @return array {list: array, total: int, page: int, pagesize: int}（ContentRankTrait::hitPage 的装配结构）
 */
/**
 * @deprecated 兼容壳：转发 ArtService::clearTopHistory(intval($days))。
 * 原注释「清理超过 N 天的 IP 记录」只说了半件事：实际删的是**文章排行主明细表与对应的 _ip 明细表**
 * 两张里 create_date 早于 (now − days*86400) 的行（QueryBuilder delete 带 where，符合防全表误写红线）。
 * 计划任务/维护脚本口径，**无事务**：两张表逐条删，中途失败会留下「主表清了、ip 表没清」的半成品状态。
 * 本壳无返回值，删了多少行拿不到；排查清理是否生效看排行表 create_date 分布，不是看返回值。
 * ⚠ 保留天数传 0 或负数会算出「未来时间点」⇒ 等价于把排行明细清空，计划任务里务必给正数。
 * @param int $days 保留窗口天数（默认 30；壳内 intval，非数字串归 0，见上）
 * @return void 无返回值（底层 clearTopHistory 也是 void）
 */
function art_del($days = 30)
{
    // hook model_top_art_del_start.php

    \App\Service\Content\ArtService::clearTopHistory(intval($days));
    // hook model_top_art_del_end.php
}
/**
 * @deprecated 兼容壳：转发 VideoService::clearTopHistory——清理 vod_top 与 vod_top_ip
 * 两张明细表中 create_date 早于 (now − $days*86400) 的行（无事务、逐表 DELETE）。
 * 新代码用 VideoService::clearTopHistory。
 * @param int $days 保留天数（调用处多为计划任务常量）
 * @return void
 */
function vod_del($days = 30)
{
    // hook model_top_vod_del_start.php

    \App\Service\Content\VideoService::clearTopHistory(intval($days));
    // hook model_top_vod_del_end.php
}
