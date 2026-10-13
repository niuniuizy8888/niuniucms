<?php
/**
 * @deprecated 兼容壳 — 全部函数转发到 \App\Service\Collection\ApiBatchService / ApiBatchRiskService / ApiBatchItemService
 * 新代码禁止调用，应直接使用对应的 Service 类。
 */

function api_import_batch_table()
{
    // hook model_api_import_batch_api_import_batch_table_start.php

    // hook model_api_import_batch_api_import_batch_table_end.php
    return \App\Service\Collection\ApiBatchService::table();
}


/** 转发 ApiBatchService::itemTable()：取 api_import_batch_item 明细表全名（string，带 tablepre 前缀；DB 未装载时回退 nncms_ 前缀）。@deprecated 兼容壳，新代码直调 Service。 */
function api_import_batch_item_table()
{
    // hook model_api_import_batch_api_import_batch_item_table_start.php

    // hook model_api_import_batch_api_import_batch_item_table_end.php
    return \App\Service\Collection\ApiBatchService::itemTable();
}


/**
 * @deprecated 兼容壳：转发 ApiBatchService::itemSupportsField()。探测明细表是否存在某列，结果按列名 static 缓存整请求；表未就绪恒返 FALSE。
 * @param string $field 列名
 * @return bool
 */
function api_import_batch_item_supports_field($field)
{
    // hook model_api_import_batch_api_import_batch_item_supports_field_start.php

    // hook model_api_import_batch_api_import_batch_item_supports_field_end.php
    return \App\Service\Collection\ApiBatchService::itemSupportsField($field);
}


/** 转发 ApiBatchService::tablesReady()：批次表与明细表两张表是否都存在（db_find_table 探测）。@return bool。@deprecated 兼容壳。 */
function api_import_batch_tables_ready()
{
    // hook model_api_import_batch_api_import_batch_tables_ready_start.php

    // hook model_api_import_batch_api_import_batch_tables_ready_end.php
    return \App\Service\Collection\ApiBatchService::tablesReady();
}


/** 转发 ApiBatchService::tablesHint()：表缺失时的固定提示文案（指引建表来源）。@return string。@deprecated 兼容壳。 */
function api_import_batch_tables_hint()
{
    // hook model_api_import_batch_api_import_batch_tables_hint_start.php

    // hook model_api_import_batch_api_import_batch_tables_hint_end.php
    return \App\Service\Collection\ApiBatchService::tablesHint();
}


/** 转发 ApiBatchService::supportsUpdatedNum()：批次表是否有 updated_num 列（static 缓存；表未就绪恒返 FALSE）。@return bool。@deprecated 兼容壳。 */
function api_import_batch_supports_updated_num()
{
    // hook model_api_import_batch_api_import_batch_supports_updated_num_start.php

    // hook model_api_import_batch_api_import_batch_supports_updated_num_end.php
    return \App\Service\Collection\ApiBatchService::supportsUpdatedNum();
}


/**
 * @deprecated 兼容壳：转发 ApiBatchService::filterUpdate()。老库无 updated_num 列时剥掉 updated_num / updated_num+ / updated_num- 三个键，避免写列报错。
 * @param mixed $update 更新数组
 * @return array 过滤后的更新数组；非数组入参返回空 array（不抛错）
 */
function api_import_batch_filter_update($update)
{
    // hook model_api_import_batch_api_import_batch_filter_update_start.php

    // hook model_api_import_batch_api_import_batch_filter_update_end.php
    return \App\Service\Collection\ApiBatchService::filterUpdate($update);
}


/**
 * @deprecated 兼容壳：转发 ApiBatchService::itemFilterRow()。按明细表实际列面剥掉老库不支持的扩展列（action_type/result_status/diff_fields/update_scope/diff_snapshot）。
 * @param mixed $row 待写入的明细行
 * @return array 过滤后的行；非数组入参返回空 array
 */
function api_import_batch_item_filter_row($row)
{
    // hook model_api_import_batch_api_import_batch_item_filter_row_start.php

    // hook model_api_import_batch_api_import_batch_item_filter_row_end.php
    return \App\Service\Collection\ApiBatchService::itemFilterRow($row);
}


/** 转发 ApiBatchService::requireTables()：表未就绪即 message(-1, tables_hint()) 终止请求（fail-closed，正常无返回）。@return void。@deprecated 兼容壳。 */
function api_import_batch_require_tables()
{
    // hook model_api_import_batch_api_import_batch_require_tables_start.php

    // hook model_api_import_batch_api_import_batch_require_tables_end.php
    return \App\Service\Collection\ApiBatchService::requireTables();
}


/**
 * @deprecated 兼容壳：转发 ApiBatchService::rawCreate()（先 filterUpdate 剥不支持列，再走 DbTableRepository::insert 预编译路径）。
 * @param array $arr 批次行数据
 * @param object|null $d 连接选择器（NULL=容器默认连接；传入连接对象则直连该库）
 * @return int|false 自增 ID（0=执行失败/无自增）；$arr 为空数组时返回 FALSE
 */
function api_import_batch__create($arr, $d = NULL)
{
    // hook model_api_import_batch_api_import_batch__create_start.php

    // hook model_api_import_batch_api_import_batch__create_end.php
    return \App\Service\Collection\ApiBatchService::rawCreate($arr, $d);
}


/**
 * @deprecated 兼容壳：转发 ApiBatchService::rawUpdate()（先 filterUpdate，再 DbTableRepository::update，where 恒为 id）。
 * @param int $id 批次 ID
 * @param array $update 更新字段（列名 +/- 后缀=自增表达式）
 * @param object|null $d 连接选择器（NULL=默认连接）
 * @return int|false 影响行数；$cond 或 $update 为空返回 FALSE
 */
function api_import_batch__update($id, $update, $d = NULL)
{
    // hook model_api_import_batch_api_import_batch__update_start.php

    // hook model_api_import_batch_api_import_batch__update_end.php
    return \App\Service\Collection\ApiBatchService::rawUpdate($id, $update, $d);
}


/** 转发 ApiBatchService::rawRead()：按 id 读单行批次。@param int $id @param object|null $d 连接选择器 @return array|null 命中行；无记录返回 null。@deprecated 兼容壳。 */
function api_import_batch__read($id, $d = NULL)
{
    // hook model_api_import_batch_api_import_batch__read_start.php

    // hook model_api_import_batch_api_import_batch__read_end.php
    return \App\Service\Collection\ApiBatchService::rawRead($id, $d);
}


/**
 * @deprecated 兼容壳：转发 ApiBatchService::rawFind() 批次表列表查询（DbTableRepository::find 语义）。
 * @param array $cond 条件数组 @param array $orderby 排序（值 1=ASC，其余 DESC） @param int $page @param int $pagesize
 * @param string $key 非空则按该列值重建索引 @param array $col 列清单（空=SELECT * 并留痕 sql_star_select） @param object|null $d 连接选择器
 * @return array 行列表；无结果返回空 array
 */
function api_import_batch__find($cond = array(), $orderby = array('id' => -1), $page = 1, $pagesize = 20, $key = 'id', $col = array(), $d = NULL)
{
    // hook model_api_import_batch_api_import_batch__find_start.php

    // hook model_api_import_batch_api_import_batch__find_end.php
    return \App\Service\Collection\ApiBatchService::rawFind($cond, $orderby, $page, $pagesize, $key, $col, $d);
}


/** 转发 ApiBatchService::rawCount()：批次表按条件计数。@param array $cond @param object|null $d 连接选择器 @return int。@deprecated 兼容壳。 */
function api_import_batch__count($cond = array(), $d = NULL)
{
    // hook model_api_import_batch_api_import_batch__count_start.php

    // hook model_api_import_batch_api_import_batch__count_end.php
    return \App\Service\Collection\ApiBatchService::rawCount($cond, $d);
}


/**
 * @deprecated 兼容壳：转发 ApiBatchService::rawDelete()。$cond 为空直接返回 FALSE（防全表删除，与 QueryBuilder 无 where 抛异常双保险）。
 * @param array $cond 删除条件 @param object|null $d 连接选择器
 * @return int|false 影响行数；空条件返回 FALSE
 */
/**
 * @deprecated 兼容壳：转发 ApiBatchService::itemRawCreate()（明细表直插，无 filter_row 剥列——调用方自负责列面兼容）。
 * @param array $arr 明细行数据 @param object|null $d 连接选择器
 * @return int|false 自增 ID（0=执行失败）；空数组返回 FALSE
 */
/**
 * @deprecated 兼容壳：转发 ApiBatchService::itemRawFind() 明细表列表查询（DbTableRepository::find 语义，$key 默认 '' 即返回顺序索引列表，pagesize 默认 100）。
 * @param array $cond @param array $orderby @param int $page @param int $pagesize @param string $key 非空按列值重建索引 @param array $col 空=SELECT * 留痕 @param object|null $d 连接选择器
 * @return array 行列表；无结果空 array
 */
function api_import_batch_item__find($cond = array(), $orderby = array('id' => -1), $page = 1, $pagesize = 100, $key = '', $col = array(), $d = NULL)
{
    // hook model_api_import_batch_api_import_batch_item__find_start.php

    // hook model_api_import_batch_api_import_batch_item__find_end.php
    return \App\Service\Collection\ApiBatchService::itemRawFind($cond, $orderby, $page, $pagesize, $key, $col, $d);
}


/** 转发 ApiBatchService::itemRawCount()：明细表按条件计数。@param array $cond @param object|null $d 连接选择器 @return int。@deprecated 兼容壳。 */
/**
 * @deprecated 兼容壳：转发 ApiBatchService::itemRawDelete()。$cond 为空返回 FALSE（防全表删除）。
 * @param array $cond 删除条件 @param object|null $d 连接选择器
 * @return int|false 影响行数；空条件 FALSE
 */
function api_import_batch_item__delete($cond = array(), $d = NULL)
{
    // hook model_api_import_batch_api_import_batch_item__delete_start.php

    // hook model_api_import_batch_api_import_batch_item__delete_end.php
    return \App\Service\Collection\ApiBatchService::itemRawDelete($cond, $d);
}


/**
 * @deprecated 兼容壳：转发 ApiBatchService::itemBigInsert()（DbTableRepository::bigInsert → QueryBuilder::bulkInsert 单条多值 INSERT）。列集合取自首行，后续行缺失列补 NULL。
 * @param array $rows 明细行数组 @param object|null $d 连接选择器
 * @return int|false 插入行数（执行失败=0）；$rows 为空返回 FALSE
 */
function api_import_batch_item_big_insert($rows, $d = NULL)
{
    // hook model_api_import_batch_api_import_batch_item_big_insert_start.php

    // hook model_api_import_batch_api_import_batch_item_big_insert_end.php
    return \App\Service\Collection\ApiBatchService::itemBigInsert($rows, $d);
}


/** 转发 ApiBatchService::create()：先 requireTables（缺表即 message 终止）再 rawCreate。@param array $arr @return int|false 语义同 api_import_batch__create()。@deprecated 兼容壳。 */
function api_import_batch_create($arr)
{
    // hook model_api_import_batch_api_import_batch_create_start.php

    // hook model_api_import_batch_api_import_batch_create_end.php
    return \App\Service\Collection\ApiBatchService::create($arr);
}


/** 转发 ApiBatchService::update()：先 requireTables 再 rawUpdate。@param int $id @param array $update @return int|false 影响行数。@deprecated 兼容壳。 */
function api_import_batch_update($id, $update)
{
    // hook model_api_import_batch_api_import_batch_update_start.php

    // hook model_api_import_batch_api_import_batch_update_end.php
    return \App\Service\Collection\ApiBatchService::update($id, $update);
}


/** 转发 ApiBatchService::read()：先 requireTables 再按 id 读单行批次。@param int $id @return array|null 无记录 null。@deprecated 兼容壳。 */
function api_import_batch_read($id)
{
    // hook model_api_import_batch_api_import_batch_read_start.php

    // hook model_api_import_batch_api_import_batch_read_end.php
    return \App\Service\Collection\ApiBatchService::read($id);
}


/** 转发 ApiBatchService::find()：requireTables 后按 id DESC、$key='id' 重建索引查批次列表。@param array $cond @param int $page @param int $pagesize @return array 以批次 id 为键的行映射，无结果空 array。@deprecated 兼容壳。 */
function api_import_batch_find($cond = array(), $page = 1, $pagesize = 20)
{
    // hook model_api_import_batch_api_import_batch_find_start.php

    // hook model_api_import_batch_api_import_batch_find_end.php
    return \App\Service\Collection\ApiBatchService::find($cond, $page, $pagesize);
}


/** 转发 ApiBatchRiskService::riskLevelOptions()：风险等级筛选下拉（all/high/medium/low => 中文）。@return array。@deprecated 兼容壳。 */
function api_import_batch_risk_level_options()
{
    // hook model_api_import_batch_api_import_batch_risk_level_options_start.php

    // hook model_api_import_batch_api_import_batch_risk_level_options_end.php
    return \App\Service\Collection\ApiBatchRiskService::riskLevelOptions();
}


/** 转发 ApiBatchRiskService::listSortOptions()：列表排序方式下拉（latest/risk_desc/skipped_desc/changed_desc => 中文）。@return array。@deprecated 兼容壳。 */
function api_import_batch_list_sort_options()
{
    // hook model_api_import_batch_api_import_batch_list_sort_options_start.php

    // hook model_api_import_batch_api_import_batch_list_sort_options_end.php
    return \App\Service\Collection\ApiBatchRiskService::listSortOptions();
}


/** 转发 ApiBatchService::count()：requireTables 后按条件统计批次数。@param array $cond @return int。@deprecated 兼容壳。 */
function api_import_batch_count($cond = array())
{
    // hook model_api_import_batch_api_import_batch_count_start.php

    // hook model_api_import_batch_api_import_batch_count_end.php
    return \App\Service\Collection\ApiBatchService::count($cond);
}


/**
 * @deprecated 兼容壳：转发 ApiBatchItemService::findByBatch()。取某批次一页明细并补展示字段：action_type/result_status 缺省值、action_type_label 中文标签、diff_fields/diff_snapshot JSON 解码为 diff_fields_arr/diff_snapshot_arr（解码失败归一空数组）。
 * @param int $batch_id 批次 ID @param int $page @param int $pagesize
 * @return array 明细行列表（顺序索引）；无结果空 array。内部先 requireTables
 */
function api_import_batch_item_find_by_batch($batch_id, $page = 1, $pagesize = 100)
{
    // hook model_api_import_batch_api_import_batch_item_find_by_batch_start.php

    // hook model_api_import_batch_api_import_batch_item_find_by_batch_end.php
    return \App\Service\Collection\ApiBatchItemService::findByBatch($batch_id, $page, $pagesize);
}


/** 转发 ApiBatchItemService::actionLabel()：动作/结果状态 → 中文标签（create/update/skip/unchanged/skipped_update/unbound）；未知状态回落「新增」。@param string $status @return string。@deprecated 兼容壳。 */
function api_import_batch_item_action_label($status)
{
    // hook model_api_import_batch_api_import_batch_item_action_label_start.php

    // hook model_api_import_batch_api_import_batch_item_action_label_end.php
    return \App\Service\Collection\ApiBatchItemService::actionLabel($status);
}


/** 转发 ApiBatchItemService::actionOptions()：动作筛选下拉（含 all 与六个具体状态 => 中文）。@return array。@deprecated 兼容壳。 */
function api_import_batch_item_action_options()
{
    // hook model_api_import_batch_api_import_batch_item_action_options_start.php

    // hook model_api_import_batch_api_import_batch_item_action_options_end.php
    return \App\Service\Collection\ApiBatchItemService::actionOptions();
}


/** 转发 ApiBatchItemService::fieldGroupOptions()：差异字段分组下拉（all/play/content/meta => 中文）。@return array。@deprecated 兼容壳。 */
function api_import_batch_item_field_group_options()
{
    // hook model_api_import_batch_api_import_batch_item_field_group_options_start.php

    // hook model_api_import_batch_api_import_batch_item_field_group_options_end.php
    return \App\Service\Collection\ApiBatchItemService::fieldGroupOptions();
}


/** 转发 ApiBatchItemService::fieldGroupLabels()：取某差异分组的中文标签清单（play/content/meta）；未知分组返回空 array。@param string $group @return array。@deprecated 兼容壳。 */
function api_import_batch_item_field_group_labels($group)
{
    // hook model_api_import_batch_api_import_batch_item_field_group_labels_start.php

    // hook model_api_import_batch_api_import_batch_item_field_group_labels_end.php
    return \App\Service\Collection\ApiBatchItemService::fieldGroupLabels($group);
}


/** 转发 ApiBatchItemService::keywordMatch()：大小写不敏感的子串匹配（mb_stripos 优先）。$needle 为空串恒返 TRUE（=不筛选）。@param string $haystack 被搜文本（非字符串入参强转） @param string $needle 关键词 @return bool。@deprecated 兼容壳。 */
function api_import_batch_item_keyword_match($haystack, $needle)
{
    // hook model_api_import_batch_api_import_batch_item_keyword_match_start.php

    // hook model_api_import_batch_api_import_batch_item_keyword_match_end.php
    return \App\Service\Collection\ApiBatchItemService::keywordMatch($haystack, $needle);
}


/**
 * @deprecated 兼容壳：转发 ApiBatchItemService::matchesFilters()。对单条明细做内存过滤三条件：动作（skip 聚合 unchanged/skipped_update/unbound）、差异字段分组（与 diff_fields_arr 求交集）、关键词（匹配名称/差异字段/前后值快照）。
 * @param array $item 明细行（含 findByBatch 补的 *_arr 字段） @param array $filters action_type/field_group/keyword
 * @return bool 是否命中全部条件
 */
function api_import_batch_item_matches_filters($item, $filters = array())
{
    // hook model_api_import_batch_api_import_batch_item_matches_filters_start.php

    // hook model_api_import_batch_api_import_batch_item_matches_filters_end.php
    return \App\Service\Collection\ApiBatchItemService::matchesFilters($item, $filters);
}


/** 转发 ApiBatchItemService::findByBatchFiltered()：取批次首页 $pagesize（默认 300）条后内存过滤。注意截断发生在过滤之前，不是全量翻页。@param int $batch_id @param array $filters @param int $pagesize @return array 命中的明细行列表。@deprecated 兼容壳。 */
/** 转发 ApiBatchItemService::filterResult()：一次最多取 fetch_limit（下限 100）条明细做内存过滤，供导出/分页/统计复用。@param int $batch_id @param array $filters @param int $fetch_limit @return array 命中行列表；无明细空 array。@deprecated 兼容壳。 */
function api_import_batch_item_filter_result($batch_id, $filters = array(), $fetch_limit = 5000)
{
    // hook model_api_import_batch_api_import_batch_item_filter_result_start.php

    // hook model_api_import_batch_api_import_batch_item_filter_result_end.php
    return \App\Service\Collection\ApiBatchItemService::filterResult($batch_id, $filters, $fetch_limit);
}


/**
 * @deprecated 兼容壳：转发 ApiBatchItemService::paginateFiltered()。基于 filterResult 的内存结果集切片分页（page 越界钳到末页；总数受 fetch_limit 截断影响）。
 * @param int $batch_id @param array $filters @param int $page @param int $pagesize @param int $fetch_limit
 * @return array {items, total, page, pagesize, totalpage}
 */
function api_import_batch_item_paginate_filtered($batch_id, $filters = array(), $page = 1, $pagesize = 20, $fetch_limit = 5000)
{
    // hook model_api_import_batch_api_import_batch_item_paginate_filtered_start.php

    // hook model_api_import_batch_api_import_batch_item_paginate_filtered_end.php
    return \App\Service\Collection\ApiBatchItemService::paginateFiltered($batch_id, $filters, $page, $pagesize, $fetch_limit);
}


/** 转发 ApiBatchItemService::exportRows()：明细行转 CSV 用中文字段行（动作/结果/VID/CID/名称/差异字段/更新范围/前后值/导入时间）。入参应为 findByBatch 补好 *_arr 字段的行。@param array $items @return array 关联数组行列表。@deprecated 兼容壳。 */
function api_import_batch_item_export_rows($items)
{
    // hook model_api_import_batch_api_import_batch_item_export_rows_start.php

    // hook model_api_import_batch_api_import_batch_item_export_rows_end.php
    return \App\Service\Collection\ApiBatchItemService::exportRows($items);
}


/** 转发 ApiBatchItemService::analyze()：明细集合的动作计数（create/update/skip）、平均差异字段数与 TOP5 字段/范围/动作。@param array $items 明细行列表 @return array 统计骨架（空入参也返回全零骨架）。@deprecated 兼容壳。 */
function api_import_batch_item_analyze($items)
{
    // hook model_api_import_batch_api_import_batch_item_analyze_start.php

    // hook model_api_import_batch_api_import_batch_item_analyze_end.php
    return \App\Service\Collection\ApiBatchItemService::analyze($items);
}


/**
 * @deprecated 兼容壳：转发 ApiBatchItemService::skipBreakdown()。统计批次内跳过类明细（unchanged/skipped_update/unbound）的占比、摘要与主导项；老库无 result_status 列或 batch_id 为空时返回 supported=FALSE 的骨架（不发起查询）。
 * @param int $batch_id @param int $fetch_limit 最多扫描条数
 * @return array {supported,total,summary,items,dominant_key,dominant_label,unchanged,skipped_update,unbound}
 */
function api_import_batch_skip_breakdown($batch_id, $fetch_limit = 5000)
{
    // hook model_api_import_batch_api_import_batch_skip_breakdown_start.php

    // hook model_api_import_batch_api_import_batch_skip_breakdown_end.php
    return \App\Service\Collection\ApiBatchItemService::skipBreakdown($batch_id, $fetch_limit);
}


/** 转发 ApiBatchItemService::attachSkipBreakdown()：给批次行追加 _skip_breakdown 字段后返回新数组（按值修改，不回写入参）。空/非数组入参原样返回。@param array $batch @param int $fetch_limit @return array。@deprecated 兼容壳。 */
function api_import_batch_attach_skip_breakdown($batch, $fetch_limit = 5000)
{
    // hook model_api_import_batch_api_import_batch_attach_skip_breakdown_start.php

    // hook model_api_import_batch_api_import_batch_attach_skip_breakdown_end.php
    return \App\Service\Collection\ApiBatchItemService::attachSkipBreakdown($batch, $fetch_limit);
}


/** 转发 ApiBatchRiskService::attachRiskProfile()：给批次行追加 _risk_profile/_risk_score/_risk_label/_risk_summary/_risk_reasons 后返回新数组（按值，不回写入参）。空/非数组原样返回。@param array $batch @return array。@deprecated 兼容壳。 */
function api_import_batch_attach_risk_profile($batch)
{
    // hook model_api_import_batch_api_import_batch_attach_risk_profile_start.php

    // hook model_api_import_batch_api_import_batch_attach_risk_profile_end.php
    return \App\Service\Collection\ApiBatchRiskService::attachRiskProfile($batch);
}


/** 转发 ApiBatchRiskService::sourceMeta()：在 CloudOpsService::codes() 资源站编码表里把 code 映射为 {code,name,type}；表不可用或未命中时 name 回落为 code 本身。@param string $code @return array。@deprecated 兼容壳。 */
function api_import_batch_source_meta($code)
{
    // hook model_api_import_batch_api_import_batch_source_meta_start.php

    // hook model_api_import_batch_api_import_batch_source_meta_end.php
    return \App\Service\Collection\ApiBatchRiskService::sourceMeta($code);
}


/** 转发 ApiBatchRiskService::score()：取批次风险画像的 score 分量。@param array $batch 批次行 @return int 0-100 风险分。@deprecated 兼容壳。 */
/** 转发 ApiBatchRiskService::label()：风险分 → 中文等级（>=70 高、>=35 中，否则低）。@param int $score @return string。@deprecated 兼容壳。 */
function api_import_batch_risk_label($score)
{
    // hook model_api_import_batch_api_import_batch_risk_label_start.php

    // hook model_api_import_batch_api_import_batch_risk_label_end.php
    return \App\Service\Collection\ApiBatchRiskService::label($score);
}


/** 转发 ApiBatchRiskService::profile()：批次风险画像。score=min(100, 跳过率分≤60 + 已回滚30 + 更新占比分≤10)，返回 {score,label,summary,reasons,primary_reason,items,dominant_*,processed/skipped/updated,各 ratio/points}。@param array $batch 批次行 @return array。@deprecated 兼容壳。 */
function api_import_batch_risk_profile($batch)
{
    // hook model_api_import_batch_api_import_batch_risk_profile_start.php

    // hook model_api_import_batch_api_import_batch_risk_profile_end.php
    return \App\Service\Collection\ApiBatchRiskService::profile($batch);
}


/** 转发 ApiBatchRiskService::drillMeta()：风险下钻入口元数据 {drill_text, drill_params}（key=skip_ratio/update_ratio/rolled_back 三种；batch_id<=0 或未知 key 返回空骨架）。@param int $batch_id @param string $key @param string $rolled_back_text 仅 key=rolled_back 时作为文案。@return array。@deprecated 兼容壳。 */
/** 转发 ApiBatchRiskService::reasonMeta()：给风险原因数组（{key,points,text}）追加下钻元数据 drill_text/drill_params 后返回新数组。@param array $batch 批次行（取 id） @param array $reason @return array。@deprecated 兼容壳。 */
/** 转发 ApiBatchRiskService::itemMeta()：给风险贡献项追加展示色 color（按 key 定色）与下钻元数据（rolled_back 文案固定「查看批次详情」）后返回新数组。@param array $batch @param array $item @return array。@deprecated 兼容壳。 */
/** 转发 ApiBatchRiskService::levelKey()：风险分 → 等级键（>=70 high、>=35 medium，否则 low），与列表 risk_level 筛选值一致。@param int $score @return string。@deprecated 兼容壳。 */
function api_import_batch_risk_level_key($score)
{
    // hook model_api_import_batch_api_import_batch_risk_level_key_start.php

    // hook model_api_import_batch_api_import_batch_risk_level_key_end.php
    return \App\Service\Collection\ApiBatchRiskService::levelKey($score);
}


/** 转发 ApiBatchRiskService::suggestions()：按风险主导项（结合跳过构成 skipBreakdown）生成处置建议文案列表（去重后顺序索引）。@param array $batch 批次行 @param int $fetch_limit 跳过明细扫描上限 @return array string[]。@deprecated 兼容壳。 */
function api_import_batch_risk_suggestions($batch, $fetch_limit = 5000)
{
    // hook model_api_import_batch_api_import_batch_risk_suggestions_start.php

    // hook model_api_import_batch_api_import_batch_risk_suggestions_end.php
    return \App\Service\Collection\ApiBatchRiskService::suggestions($batch, $fetch_limit);
}


/**
 * @deprecated 兼容壳：转发 ApiBatchService::listPrepare()。对批次列表逐行挂风险画像 → 按 filters.risk_level 内存过滤 → 按 sort（latest/risk_desc/skipped_desc/changed_desc）usort → 内存分页（page 越界钳到末页）。
 * @param array $batches 批次行列表 @param array $filters 目前仅用 risk_level（all/high/medium/low） @param string $sort @param int $page @param int $pagesize
 * @return array {items, all_items（过滤+排序后的全量）, total, page, pagesize, totalpage}
 */
function api_import_batch_list_prepare($batches, $filters = array(), $sort = 'latest', $page = 1, $pagesize = 20)
{
    // hook model_api_import_batch_api_import_batch_list_prepare_start.php

    // hook model_api_import_batch_api_import_batch_list_prepare_end.php
    return \App\Service\Collection\ApiBatchService::listPrepare($batches, $filters, $sort, $page, $pagesize);
}


/**
 * @deprecated 兼容壳：转发 ApiBatchService::listAnalyze()。批次列表聚合统计：完成/回滚批次计数、累计导入/更新量、平均风险分、变更最多/跳过最多/风险最高三个 TOP 批次（后两者再挂 _skip_breakdown）。
 * @param array $batches 批次行列表
 * @return array 统计骨架（空入参返回全零骨架；top_* 无候选时为空 array）
 */
function api_import_batch_list_analyze($batches)
{
    // hook model_api_import_batch_api_import_batch_list_analyze_start.php

    // hook model_api_import_batch_api_import_batch_list_analyze_end.php
    return \App\Service\Collection\ApiBatchService::listAnalyze($batches);
}


/** 转发 ApiBatchService::appendItems()：逐行 itemFilterRow 剥不支持列后 bigInsert 批量补写批次明细。@param int $batch_id @param array $rows @return int|false 插入行数；batch_id/rows 为空或过滤后无有效行返回 FALSE。@deprecated 兼容壳。 */
function api_import_batch_append_items($batch_id, $rows)
{
    // hook model_api_import_batch_api_import_batch_append_items_start.php

    // hook model_api_import_batch_api_import_batch_append_items_end.php
    return \App\Service\Collection\ApiBatchService::appendItems($batch_id, $rows);
}


/** 转发 ApiBatchService::markFinished()：写批次收口状态 status、finish_date=time()（$note 非空才写 note 列）。@param int $batch_id @param int $status 1=完成 @param string $note @return int|false 影响行数；batch_id 为空 FALSE。@deprecated 兼容壳。 */
function api_import_batch_mark_finished($batch_id, $status = 1, $note = '')
{
    // hook model_api_import_batch_api_import_batch_mark_finished_start.php

    // hook model_api_import_batch_api_import_batch_mark_finished_end.php
    return \App\Service\Collection\ApiBatchService::markFinished($batch_id, $status, $note);
}


/**
 * @deprecated 兼容壳：转发 ApiBatchService::rollbackRaw()。按批次明细回滚导入内容——不可逆副作用：VideoService::deleteAll 删视频、清空该批次明细、批次置 status=2 并写 rollback_date/rollback_num、cate_list_cache_delete() 清分类缓存。
 * @param int $batch_id
 * @return array {code, message[, rollback_num]}；code=-1 批次不存在或已回滚，code=0 成功（无明细也算成功）
 */
function api_import_batch_rollback($batch_id)
{
    // hook model_api_import_batch_api_import_batch_rollback_start.php

    // hook model_api_import_batch_api_import_batch_rollback_end.php
    return \App\Service\Collection\ApiBatchService::rollbackRaw($batch_id);
}

