<?php
namespace App\Service\Collection;

class ApiBatchItemService
{

    /**
     * 取某采集批次的一页明细行并补齐展示字段（批次明细页/导出/分析的公共底座）。
     *
     * 前置 api_import_batch_require_tables()：批次表或明细表缺失时直接 message(-1) 终止请求
     * （不是返回空数组），所以调用方无需再判建表状态。经兼容壳 api_import_batch_item__find()
     * → ApiBatchService::itemRawFind()（$key='' ⇒ 返回数字下标的顺序列表，按 id 降序=最新在前）。
     *
     * 逐行补的派生字段：action_type 缺省 'create'、result_status 缺省取 action_type、
     * action_type_label 中文标签，以及把 diff_fields / diff_snapshot 两列的 JSON 解码为
     * diff_fields_arr / diff_snapshot_arr（解码失败或非数组一律归零成空数组，不让脏 JSON 打穿视图）。
     *
     * @param int $batch_id 批次 id
     * @param int $page 页码（1 起）
     * @param int $pagesize 每页条数（默认 100）
     * @return array 明细行列表（数字下标）；无明细返回空数组
     */
    public static function findByBatch($batch_id, $page = 1, $pagesize = 100)
    {
        api_import_batch_require_tables();
        $items = api_import_batch_item__find(array('batch_id' => $batch_id), array('id' => -1), $page, $pagesize);
        if (empty($items)) return $items;
        foreach ($items as &$item) {
            $item['action_type'] = $item['action_type'] ?? 'create';
            $item['result_status'] = $item['result_status'] ?? $item['action_type'];
            $item['action_type_label'] = api_import_batch_item_action_label($item['result_status']);
            $item['diff_fields_arr'] = !empty($item['diff_fields']) ? xn_json_decode($item['diff_fields']) : array();
            if (!is_array($item['diff_fields_arr'])) {
                $item['diff_fields_arr'] = array();
            }
            $item['diff_snapshot_arr'] = !empty($item['diff_snapshot']) ? xn_json_decode($item['diff_snapshot']) : array();
            if (!is_array($item['diff_snapshot_arr'])) {
                $item['diff_snapshot_arr'] = array();
            }
        }
        return $items;
    }

    /** 动作/结果状态的中文标签（create 新增 / update 更新 / skip 全部跳过 / unchanged 无变化 / skipped_update 跳过更新 / unbound 未绑定分类）。⚠ 未知或空状态一律回落「新增」——老库无 result_status 列时的兜底口径，不是判定依据。@param string $status 状态原值 @return string */
    public static function actionLabel($status)
    {
        $labels = array(
            'create' => '新增',
            'update' => '更新',
            'skip' => '全部跳过',
            'unchanged' => '无变化',
            'skipped_update' => '跳过更新',
            'unbound' => '未绑定分类',
        );
        return $labels[$status] ?? '新增';
    }

    /** 后台「动作」筛选下拉的选项集（key=>中文），含 all 与六个具体状态；与 actionLabel() 的标签表同源同口径，改一处要同步另一处。@return array 状态值=>显示文案 */
    public static function actionOptions()
    {
        return array(
            'all' => '全部',
            'create' => '新增',
            'update' => '更新',
            'skip' => '全部跳过',
            'unchanged' => '无变化',
            'skipped_update' => '跳过更新',
            'unbound' => '未绑定分类',
        );
    }

    /** 后台「差异字段分组」下拉选项（all/play/content/meta => 中文）；分组到具体字段的映射见 fieldGroupLabels()。@return array 分组键=>显示文案 */
    public static function fieldGroupOptions()
    {
        return array(
            'all' => '全部字段',
            'play' => '播放器字段',
            'content' => '封面与简介',
            'meta' => '分类与资料',
        );
    }

    /** 取某差异分组的中文标签清单（play=播放器 5 项 / content=封面与简介 7 项 / meta=分类与资料 9 项），供 matchesFilters() 与明细行的 diff_fields_arr（存的是中文标签，非列名）求交集。未知分组返回空数组 ⇒ 分组筛选会判为「无命中」。@param string $group 分组键 @return array 中文标签列表 */
    public static function fieldGroupLabels($group)
    {
        $groups = array(
            'play' => array('更新时间', '播放来源', '播放服务器', '播放备注', '播放地址'),
            'content' => array('封面', '演员', '导演', '编剧', '简介', '备注', '内容'),
            'meta' => array('分类', '资源站ID', '英文名', '首字母', '地区', '语言', '年份', '豆瓣ID', '豆瓣分'),
        );
        return $groups[$group] ?? array();
    }

    /** 大小写不敏感的子串匹配（有 mb_stripos 走 mb，否则回落 stripos）。两个边界：$needle 为空串恒返 TRUE（=不参与过滤）；非字符串 $haystack 先强转 string。只做匹配、不做转义或剥离，命中结果进模板 {$var} 裸 echo 前仍要由上游保证入库侧已转义（安全红线 §二.4）。@param string $haystack 被搜文本 @param string $needle 关键词 @return bool */
    public static function keywordMatch($haystack, $needle)
    {
        if ($needle === '') return TRUE;
        if (!is_string($haystack)) $haystack = (string)$haystack;
        if (function_exists('mb_stripos')) {
            return mb_stripos($haystack, $needle) !== FALSE;
        }
        return stripos($haystack, $needle) !== FALSE;
    }

    /**
     * 对单条明细做内存过滤（三条件 AND，任一不满足即 FALSE）——批次明细量大时在 PHP 侧筛，
     * 不拼 LIKE/IN 打 SQL，因此必须先经 findByBatch() 补出 diff_fields_arr 等派生字段。
     *
     *  · action_type：'all' 跳过；'skip' 是聚合口径，命中 unchanged/skipped_update/unbound 三者之一；
     *    其余值与 result_status（缺失回落 action_type，再缺失按 'create'）严格比较（in_array/=== 均走严格）。
     *  · field_group：'all' 跳过；否则要求该行差异标签与本分组标签有交集（分组未知/无差异 ⇒ 不命中）。
     *  · keyword：'all' 之外按「名称 → 差异字段串 → 前后值快照(label/old/new)」顺序试匹配，命中即过。
     *
     * @param array $item findByBatch() 产出的明细行
     * @param array $filters 可含 action_type / field_group / keyword 三键（缺省按 'all'/''）
     * @return bool 是否同时满足全部条件
     */
    public static function matchesFilters($item, $filters = array())
    {
        $action_type = $filters['action_type'] ?? 'all';
        $field_group = $filters['field_group'] ?? 'all';
        $keyword = trim((string)($filters['keyword'] ?? ''));

        $item_action = $item['result_status'] ?? ($item['action_type'] ?? 'create');
        if ($action_type !== 'all') {
            if ($action_type === 'skip') {
                if (!in_array($item_action, array('unchanged', 'skipped_update', 'unbound'), TRUE)) {
                    return FALSE;
                }
            } elseif ($item_action !== $action_type) {
                return FALSE;
            }
        }

        if ($field_group !== 'all') {
            $labels = api_import_batch_item_field_group_labels($field_group);
            $diff_fields_arr = $item['diff_fields_arr'] ?? array();
            if (empty($labels) || empty($diff_fields_arr) || empty(array_intersect($labels, $diff_fields_arr))) {
                return FALSE;
            }
        }

        if ($keyword !== '') {
            $matched = FALSE;
            if (api_import_batch_item_keyword_match($item['vod_name'] ?? '', $keyword)) {
                $matched = TRUE;
            }
            if (!$matched && !empty($item['diff_fields_arr'])) {
                $matched = api_import_batch_item_keyword_match(implode(' ', $item['diff_fields_arr']), $keyword);
            }
            if (!$matched && !empty($item['diff_snapshot_arr'])) {
                foreach ($item['diff_snapshot_arr'] as $snapshot) {
                    $text = ($snapshot['label'] ?? '') . ' ' . ($snapshot['old'] ?? '') . ' ' . ($snapshot['new'] ?? '');
                    if (api_import_batch_item_keyword_match($text, $keyword)) {
                        $matched = TRUE;
                        break;
                    }
                }
            }
            if (!$matched) {
                return FALSE;
            }
        }

        return TRUE;
    }

    /** 取批次首页 $pagesize（默认 300）条再内存过滤。⚠ 截断发生在过滤**之前**——不是全量翻页，翻页/导出请改用 filterResult()/paginateFiltered()（口径与 fetch_limit 可控）。@param int $batch_id @param array $filters 见 matchesFilters() @param int $pagesize 过滤前最多取回条数 @return array 命中行列表；无明细时原样返回 findByBatch() 的空结果 */
    /** 一次最多取 $fetch_limit 条明细做内存过滤（导出、分页、跳过统计共用的底座）。$fetch_limit 下限钳到 100；超过上限的明细**不参与**过滤与统计，故 total 类数字都是「已取回集合」的口径。@param int $batch_id @param array $filters @param int $fetch_limit 取回上限（默认 5000） @return array 命中行列表；无明细返回空数组 */
    public static function filterResult($batch_id, $filters = array(), $fetch_limit = 5000)
    {
        $fetch_limit = max(100, intval($fetch_limit));
        $items = api_import_batch_item_find_by_batch($batch_id, 1, $fetch_limit);
        if (empty($items)) return array();
        $filtered = array();
        foreach ($items as $item) {
            if (api_import_batch_item_matches_filters($item, $filters)) {
                $filtered[] = $item;
            }
        }
        return $filtered;
    }

    /** 基于 filterResult() 内存结果集做 array_slice 分页：$page/$pagesize 先钳到 >=1，$page 越界自动回落到末页（不返回空页）；$totalpage 在结果集为空时给 1（不是 0）。@param int $batch_id @param array $filters @param int $page @param int $pagesize @param int $fetch_limit 过滤前取回上限（受其截断影响） @return array ['items'=>array,'total'=>int,'page'=>int,'pagesize'=>int,'totalpage'=>int] */
    public static function paginateFiltered($batch_id, $filters = array(), $page = 1, $pagesize = 20, $fetch_limit = 5000)
    {
        $page = max(1, intval($page));
        $pagesize = max(1, intval($pagesize));
        $filtered = api_import_batch_item_filter_result($batch_id, $filters, $fetch_limit);
        $total = count($filtered);
        $totalpage = $total > 0 ? intval(ceil($total / $pagesize)) : 1;
        if ($page > $totalpage) {
            $page = $totalpage;
        }
        $offset = ($page - 1) * $pagesize;
        $items = array_slice($filtered, $offset, $pagesize);
        return array(
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pagesize' => $pagesize,
            'totalpage' => $totalpage,
        );
    }

    /** 明细行转「中文字段名」的导出行（动作/结果/VID/CID/名称/差异字段/更新范围/前后值/导入时间 九列）：前后值拼成 label: old => new（缺 label 回落 field），差异字段用「、」连接，时间戳按 date('Y-m-d H:i:s') 格式化。入参须是 findByBatch() 补好 *_arr/_label 的行，直传原始库行会丢派生列。本方法不做 CSV 转义与公式注入中和——那步在 ApiBatchService::exportCsv() 的 $csvSafe 里。@param array $items 明细行列表 @return array 关联数组行列表（键为中文列名，随首行做表头） */
    public static function exportRows($items)
    {
        $rows = array();
        foreach ($items as $item) {
            $snapshot_text = '';
            if (!empty($item['diff_snapshot_arr'])) {
                $parts = array();
                foreach ($item['diff_snapshot_arr'] as $snapshot) {
                    $parts[] = ($snapshot['label'] ?? ($snapshot['field'] ?? '-')) . ': ' . ($snapshot['old'] ?? '') . ' => ' . ($snapshot['new'] ?? '');
                }
                $snapshot_text = implode(' | ', $parts);
            }
            $rows[] = array(
                '动作' => $item['action_type_label'] ?? '新增',
                '结果' => api_import_batch_item_action_label($item['result_status'] ?? ($item['action_type'] ?? 'create')),
                'VID' => intval($item['vid'] ?? 0),
                'CID' => intval($item['cid'] ?? 0),
                '名称' => (string)($item['vod_name'] ?? ''),
                '差异字段' => !empty($item['diff_fields_arr']) ? implode('、', $item['diff_fields_arr']) : '',
                '更新范围' => (string)($item['update_scope'] ?? ''),
                '前后值' => $snapshot_text,
                '导入时间' => empty($item['create_date']) ? '' : date('Y-m-d H:i:s', intval($item['create_date'])),
            );
        }
        return $rows;
    }

    /** 明细集合的动作统计：按 result_status（回落 action_type，再回落 'create'）分桶计数——'update' 计更新、unchanged/skipped_update/unbound 计跳过、其余全算新增；同时累加平均差异字段数与 TOP5 字段/更新范围/动作（arsort 降序 + array_slice 取前 5）。注意 'skip' 这个状态值本身不进 skip_count（只认三个细分状态）。@param array $items 明细行列表 @return array 统计骨架（total/create_count/update_count/skip_count/avg_diff_fields/top_fields/top_scopes/top_actions）；空入参返回全零骨架而非 NULL */
    public static function analyze($items)
    {
        $stats = array(
            'total' => count($items),
            'create_count' => 0,
            'update_count' => 0,
            'skip_count' => 0,
            'avg_diff_fields' => 0,
            'top_fields' => array(),
            'top_scopes' => array(),
            'top_actions' => array(),
        );
        if (empty($items)) return $stats;

        $field_counter = array();
        $scope_counter = array();
        $action_counter = array();
        $diff_sum = 0;

        foreach ($items as $item) {
            $action = $item['result_status'] ?? ($item['action_type'] ?? 'create');
            if ($action === 'update') {
                $stats['update_count']++;
            } elseif (in_array($action, array('unchanged', 'skipped_update', 'unbound'), TRUE)) {
                $stats['skip_count']++;
            } else {
                $stats['create_count']++;
            }
            isset($action_counter[$action]) ? $action_counter[$action]++ : $action_counter[$action] = 1;

            $scope = $item['update_scope'] ?? '';
            if ($scope !== '') {
                isset($scope_counter[$scope]) ? $scope_counter[$scope]++ : $scope_counter[$scope] = 1;
            }

            $diff_fields = $item['diff_fields_arr'] ?? array();
            $diff_sum += count($diff_fields);
            foreach ($diff_fields as $field_label) {
                isset($field_counter[$field_label]) ? $field_counter[$field_label]++ : $field_counter[$field_label] = 1;
            }
        }

        arsort($field_counter);
        arsort($scope_counter);
        arsort($action_counter);

        $stats['avg_diff_fields'] = round($diff_sum / max(1, count($items)), 2);
        $stats['top_fields'] = array_slice($field_counter, 0, 5, TRUE);
        $stats['top_scopes'] = array_slice($scope_counter, 0, 5, TRUE);
        $stats['top_actions'] = array_slice($action_counter, 0, 5, TRUE);
        return $stats;
    }

    /** 批次「跳过」明细的构成分析（unchanged/skipped_update/unbound 各自的条数与占比、summary 文案、主导项 dominant_key/label）。老库没有 result_status 列或 $batch_id 为空时返回 supported=FALSE 的骨架且不发起查询 ⇒ 视图须先判 supported。取数复用 filterResult()，受 $fetch_limit 截断。@param int $batch_id @param int $fetch_limit 最多扫描条数（默认 5000） @return array {supported,total,summary,items,dominant_key,dominant_label,unchanged,skipped_update,unbound} */
    public static function skipBreakdown($batch_id, $fetch_limit = 5000)
    {
        $stats = array(
            'supported' => FALSE,
            'total' => 0,
            'summary' => '',
            'items' => array(),
            'dominant_key' => '',
            'dominant_label' => '',
            'unchanged' => 0,
            'skipped_update' => 0,
            'unbound' => 0,
        );
        if (empty($batch_id) || !api_import_batch_item_supports_field('result_status')) {
            return $stats;
        }

        $items = api_import_batch_item_filter_result($batch_id, array(
            'action_type' => 'skip',
            'field_group' => 'all',
            'keyword' => '',
        ), $fetch_limit);
        $stats['supported'] = TRUE;
        $stats['total'] = count($items);
        if (empty($items)) {
            $stats['summary'] = '暂无跳过明细';
            return $stats;
        }

        $counter = array(
            'unchanged' => 0,
            'skipped_update' => 0,
            'unbound' => 0,
        );
        foreach ($items as $item) {
            $status = $item['result_status'] ?? '';
            if (isset($counter[$status])) {
                $counter[$status]++;
            }
        }

        $summary_parts = array();
        $dominant_key = '';
        $dominant_count = -1;
        foreach ($counter as $status => $count) {
            $stats[$status] = $count;
            if ($count <= 0) {
                continue;
            }
            if ($count > $dominant_count) {
                $dominant_count = $count;
                $dominant_key = $status;
            }
            $ratio = round($count / max(1, $stats['total']) * 100, 1);
            $stats['items'][] = array(
                'key' => $status,
                'label' => api_import_batch_item_action_label($status),
                'count' => $count,
                'ratio' => $ratio,
            );
            $summary_parts[] = api_import_batch_item_action_label($status) . ' ' . $count . '（' . $ratio . '%）';
        }

        $stats['summary'] = !empty($summary_parts) ? implode(' / ', $summary_parts) : '暂无跳过明细';
        $stats['dominant_key'] = $dominant_key;
        $stats['dominant_label'] = $dominant_key !== '' ? api_import_batch_item_action_label($dominant_key) : '';
        return $stats;
    }

    /** 给批次行追加 _skip_breakdown 字段（skipBreakdown(intval($batch['id']))）后**返回新数组**——值传递不回写入参，调用方必须接住返回值；入参为空或非数组时原样返回。@param array $batch 批次行 @param int $fetch_limit 透传给 skipBreakdown() @return array|mixed 追加派生键的批次行；非数组入参为原值（类型不定） */
    public static function attachSkipBreakdown($batch, $fetch_limit = 5000)
    {
        if (empty($batch) || !is_array($batch)) {
            return $batch;
        }
        $batch['_skip_breakdown'] = api_import_batch_skip_breakdown(intval($batch['id'] ?? 0), $fetch_limit);
        return $batch;
    }

}
