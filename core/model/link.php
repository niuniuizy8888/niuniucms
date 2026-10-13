<?php
/**
 * @deprecated 兼容壳 — link__* 转发到 RepositoryFactory，link_* 转发到 \App\Service\Content\LinkService
 * 新代码禁止调用，应直接使用 LinkService 或 RepositoryFactory。
 */
// hook model_link_start.php
/**
 * @deprecated 兼容壳（文件头禁令：link__ 一族转发 RepositoryFactory，link_ 一族转发 LinkService，
 * 新代码禁止调用）：注意名字虽叫 create，底层其实是 **replace()** —— 友情链接表的
 * REPLACE INTO 语义（主键/唯一键冲突则先删后插整行），所以重复调用是 upsert、不会堆重复行，
 * 但**未出现在 $arr 里的列会被清成表默认值**（不是「只更新给的字段」）。
 * 与 LinkService 的差别：本壳**不失效 listCache 版本令牌**，直接改库后前台链接块会滞留到 TTL（3600s）过期；
 * 后台维护链接请走 LinkService。列名/值走标识符白名单 + 参数绑定（§二.3），不做 HTML 转义（§二.4）。
 * @param array $arr 列名 => 值 的整行数据；空数组由底层短路
 * @param object|null $d 连接/从库选择器（NULL = 默认连接）
 * @return int|false REPLACE 的影响数（底层语义，无自增列时为 0）；失败 FALSE
 */
/**
 * @deprecated 兼容壳：转发 RepositoryFactory::link()->update($cond, $update, $d)——link 表条件更新。
 * 与带缓存代际的 LinkService::update 不同：本壳不清 listCache 版本令牌，直接改库会让前台缓存滞留到 TTL（3600s）过期，慎用。
 * ⚠ 底层无 where 的 update 抛异常（防全表误写），$cond 不可为空。
 * @param array $cond   WHERE 条件
 * @param array $update SET 列（列名 +/- 后缀=自增表达式）
 * @param \PdoDriver|null $d 连接/从库选择器
 * @return int|false 影响行数（值未变更 MySQL 给 0 属正常）；空参=false
 */
/**
 * @deprecated 兼容壳：转发 RepositoryFactory::link()->find()——link 表条件分页行集（无过滤条件时返回全表分页）。
 * @param array  $cond     WHERE 条件
 * @param array  $orderby  排序 ['列'=>1/-1]
 * @param int    $page     页码（1 起）
 * @param int    $pagesize 每页条数
 * @param string $key      结果以哪列值为键（默认 id）
 * @param array  $col      列清单，空=SELECT *（sql_star_select 留痕）
 * @param \PdoDriver|null $d 连接/从库选择器
 * @return array 行列表；无结果=空数组
 */
/**
 * @deprecated 兼容壳：转发 RepositoryFactory::link()->delete($cond, $d)——同 link__update 的提醒：不换代 link_cache_ver，前台缓存会滞留。
 * @param array $cond WHERE 条件（空数组底层短路 false，防无 where 全表删）
 * @param \PdoDriver|null $d 连接/从库选择器
 * @return int|false 影响行数（0=无匹配）；空参=false
 */
/**
 * @deprecated 兼容壳：转发 RepositoryFactory::link()->count($cond, $d)——友链表条件计数。
 * （LinkService 也有同名 count()，语义一致。）
 * @param array $cond WHERE 条件
 * @param \PdoDriver|null $d 连接/从库选择器
 * @return int 签名 : int（语句失败归 0）
 */
function link_count($cond = array(), $d = NULL)
{
    // hook model_link_link_count_start.php

    $n = \App\Repository\RepositoryFactory::link()->count($cond, $d);
    // hook model_link_link_count_end.php
    return $n;
}
/**
 * @deprecated 兼容壳：转发 LinkService::create((array)$arr)——REPLACE INTO 友链行并推进 link_cache_ver 换代前台缓存。
 * @param mixed $arr link 表行数据（array 之外会被 (array) 强转）
 * @return int|false REPLACE 的 lastInsertId（执行失败坍缩为 0）；空数据=FALSE
 */
function link_create($arr)
{
    // hook model_link_link_create_start.php

    // hook model_link_link_create_end.php
    return \App\Service\Content\LinkService::create((array)$arr);
}
/**
 * @deprecated 兼容壳：转发 LinkService::update(intval($id), (array)$update)——按主键更新并换代 link_cache_ver。
 * @param mixed $id     主键（0 短路 FALSE）
 * @param mixed $update SET 字段数组
 * @return int|false 影响行数；$id/$update 为空=FALSE
 */
/**
 * @deprecated 兼容壳：转发 LinkService::find()——无条件分页、恒 rank DESC；未传列 ⇒ SELECT *（link 列窄，可接受兜底）。
 * 前台展示请用 link_get（带缓存版本令牌）；本壳每次直查库。
 * @param int $page     页码（1 起）
 * @param int $pagesize 每页条数（默认 100≈近全量一页）
 * @return array 行列表；无结果=空数组
 */
/**
 * @deprecated 兼容壳：转发 LinkService::delete(intval($id))——按主键物理删除并换代 link_cache_ver。
 * @param mixed $id 主键（0 短路 FALSE）
 * @return int|false 影响行数（id 不存在为 0，不算失败）
 */
function link_delete($id)
{
    // hook model_link_link_delete_start.php

    // hook model_link_link_delete_end.php
    return \App\Service\Content\LinkService::delete(intval($id));
}
/**
 * @deprecated 兼容壳：转发 LinkService::listCache()——前台友链列表热路径：键 friends_link_{ver}_{page}_{pagesize}
 * 走 MultiLayerCache（TTL 3600s），写路径经 deleteCache() 换代 link_cache_ver 即时失效。
 * @param int $page     页码（1 起）
 * @param int $pagesize 每页条数
 * @return array 行列表；空结果/异常恒归一为空数组（不返回 NULL）
 */
function link_get($page = 1, $pagesize = 100)
{
    // hook model_link_link_get_start.php

    // hook model_link_link_get_end.php
    return \App\Service\Content\LinkService::listCache(intval($page), intval($pagesize));
}
