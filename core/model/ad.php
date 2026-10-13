<?php
/**
 * @deprecated 兼容壳：转发 table('ad','id')->replace($arr, $d)——REPLACE INTO 整行覆盖写，
 * 未提供的列回落表默认值（须传全量列）。
 * @param array $arr 列=>值 待写行（含主键 id 才有 replace 语义；非法列名被 safeColumns 剔除）
 * @param \PdoDriver|null $d 连接选择器
 * @return int|false lastInsertId（无自增/语句失败=0）；$arr 为空返回 FALSE
 */
function ad__create($arr = array(), $d = NULL)
{
    // hook model_ad_ad__create_start.php

    $r = \App\Repository\RepositoryFactory::table('ad', 'id')->replace($arr, $d);
    // hook model_ad_ad__create_end.php
    return $r;
}
/**
 * @deprecated 兼容壳：转发 table('ad','id')->update($cond, $update, $d)（QueryBuilder 真·预编译）。
 * 列名可带 '+'/'-' 后缀做原子自增/自减。$cond 空直接返回 FALSE（QueryBuilder 无 where 会抛异常，双保险防全表误写）。
 * @param array $cond 条件（applyCond 语法：列=>值 或 列=>array('<='=>x)）
 * @param array $update 列=>值
 * @param \PdoDriver|null $d 连接选择器
 * @return int|false 受影响行数（值未变更时 MySQL 返回 0，属正常）；空参返回 FALSE
 */
function ad__update($cond = array(), $update = array(), $d = NULL)
{
    // hook model_ad_ad__update_start.php

    $r = \App\Repository\RepositoryFactory::table('ad', 'id')->update($cond, $update, $d);
    // hook model_ad_ad__update_end.php
    return $r;
}
/**
 * @deprecated 兼容壳：转发 table('ad','id')->find()——ad 表条件分页查询，结果以 $key（默认 id）为键。
 * $col 传空数组会落 SELECT *（QueryBuilder 口径，ad 列面窄代价小）；排序传列=>1/-1。
 * @param array $cond 查询条件（applyCond 语法）
 * @param array $orderby 列=>1(ASC)/-1(DESC)
 * @param int $page 页码（1 起）
 * @param int $pagesize 页宽
 * @param string $key 结果重排键列
 * @param array $col 显式列清单（空=全列）
 * @param \PdoDriver|null $d 连接选择器
 * @return array id 为键的行数组；无结果空数组
 */
function ad__find($cond = array(), $orderby = array(), $page = 1, $pagesize = 20, $key = 'id', $col = array(), $d = NULL)
{
    // hook model_ad_ad__find_start.php

    $arr = \App\Repository\RepositoryFactory::table('ad', 'id')->find($cond, $orderby, $page, $pagesize, $key, $col, $d);
    // hook model_ad_ad__find_end.php
    return $arr;
}
/**
 * @deprecated 兼容壳：转发 table('ad','id')->delete($cond, $d)——条件删除广告行。
 * $cond 为空直接 FALSE（QueryBuilder 无 where 删除会抛异常，防全表误删的双保险）。
 * @param array $cond 删除条件（applyCond 语法）
 * @param \PdoDriver|null $d 连接选择器
 * @return int|false 受影响行数（无匹配=0）；空条件 FALSE
 */
function ad__delete($cond = array(), $d = NULL)
{
    // hook model_ad_ad__delete_start.php

    $r = \App\Repository\RepositoryFactory::table('ad', 'id')->delete($cond, $d);
    // hook model_ad_ad__delete_end.php
    return $r;
}
/**
 * @deprecated 兼容壳：转发 table('ad','id')->count($cond, $d)——COUNT(*)，空条件=全表计数。
 * @param array $cond 查询条件（applyCond 语法）
 * @param \PdoDriver|null $d 连接选择器
 * @return int
 */
function ad_count($cond = array(), $d = NULL)
{
    // hook model_ad_ad_count_start.php

    $n = \App\Repository\RepositoryFactory::table('ad', 'id')->count($cond, $d);
    // hook model_ad_ad_count_end.php
    return $n;
}
/**
 * @deprecated 兼容壳：空 $arr 短路 FALSE，否则转 ad__create()（REPLACE INTO 整行覆盖，见彼处语义）。
 * @param array $arr 列=>值 广告行
 * @return int|false 写入结果（同 ad__create）；空入参 FALSE
 */
function ad_create($arr)
{
    // hook model_ad_ad_create_start.php

    if (empty($arr)) return FALSE;
    $r = ad__create($arr);
    // hook model_ad_ad_create_end.php
    return $r;
}
/**
 * @deprecated 兼容壳：按主键更新单条广告——$id/$update 任一为空先短路 FALSE，
 * 否则转发 ad__update(array('id' => $id), $update)。
 * @param int|string $id 广告主键
 * @param array $update 列=>值（+/- 后缀=原子增减）
 * @return int|false 受影响行数；空参 FALSE
 */
function ad_update($id, $update)
{
    // hook model_ad_ad_update_start.php

    if (empty($id) || empty($update)) return FALSE;
    $r = ad__update(array('id' => $id), $update);
    // hook model_ad_ad_update_end.php
    return $r;
}
/**
 * @deprecated 兼容壳：按主键删除单条广告——$id 为空先短路 FALSE，
 * 否则转发 ad__delete(array('id' => $id))。
 * @param int|string $id 广告主键
 * @return int|false 受影响行数；空参 FALSE
 */
function ad_delete($id)
{
    // hook model_ad_ad_delete_start.php

    if (empty($id)) return FALSE;
    $r = ad__delete(array('id' => $id));
    // hook model_ad_ad_delete_end.php
    return $r;
}
/**
 * @deprecated 兼容壳：广告全量列表（无过滤条件、id 降序）分页取回，转发 ad__find()。
 * ⚠ 首参写法 `ad__find($cond = array(), ...)` 是**内联赋值传值**（等价传空数组），非具名参数，
 *   与 ad__find 的形参名同形易误读——行为正确，此处仅如实记录（未动代码）。
 * @param int $page 页码（1 起）
 * @param int $pagesize 页宽（默认 20）
 * @return array id 为键的广告行数组（$col 未传 ⇒ 走 SELECT * 口径）
 */
function ad_find($page = 1, $pagesize = 20)
{
    // hook model_ad_ad_find_start.php

    $arr = ad__find($cond = array(), array('id' => -1), $page, $pagesize);
    // hook model_ad_ad_find_end.php
    return $arr;
}
