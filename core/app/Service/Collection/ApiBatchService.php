<?php
namespace App\Service\Collection;

class ApiBatchService
{
    /**
     * 采集批次管理页视图数据装配：在 list() 结果上追加两组分页 HTML。
     *
     * @param array $params 前台请求参数（batch_id/page/item_page/过滤项等，均可缺省）
     * @param int   $uid    当前管理员 uid，用于签发回滚场景 safe_token
     * @return array list() 的全量键 + batch_items_pagination/batch_list_pagination（均为 pagination() 生成的 HTML 串）
     */
    public static function page(array $params, int $uid): array
    {
        $data = self::list($params, $uid);
        // EXTR_SKIP 纵深防御（S-4 同款）：list() 返回内部固定键，与局部 $data/$params/$uid 无碰撞，
        // SKIP 不改变现行为，仅杜绝未来键名演化时意外覆盖方法作用域变量
        extract($data, EXTR_SKIP);

        $batch_item_extra = array(
            'batch_id' => $batch_id,
            'page' => $page,
            'action_type' => $batch_filter_action_type,
            'field_group' => $batch_filter_field_group,
            'keyword' => $batch_filter_keyword,
            'item_page' => '{page}',
            'item_pagesize' => $batch_items_pagesize,
        );
        $data['batch_items_pagination'] = !empty($batch) ? pagination(url('api-batch', $batch_item_extra, TRUE), $batch_items_total, $batch_items_page, $batch_items_pagesize) : '';

        $batch_list_extra = array(
            'page' => '{page}',
            'batch_id' => $batch_id,
            'item_page' => $item_page,
            'item_pagesize' => $item_pagesize,
            'action_type' => $batch_filter_action_type,
            'field_group' => $batch_filter_field_group,
            'keyword' => $batch_filter_keyword,
            'risk_level' => $batch_risk_level,
            'batch_sort' => $batch_sort,
        );
        $data['batch_list_pagination'] = pagination(url('api-batch', $batch_list_extra, TRUE), $batch_count, $page, 20);
        return $data;
    }

    /**
     * 获取批次列表及相关分析数据
     * 
     * @param array $params 请求参数
     * @param int $uid 用户ID
     * @return array 视图所需的数据
     */
    public static function list(array $params, int $uid): array
    {
        api_import_batch_require_tables();
        
        $batch_id = intval($params['batch_id'] ?? 0);
        $page = intval($params['page'] ?? 1);
        $item_page = intval($params['item_page'] ?? 1);
        $item_pagesize = intval($params['item_pagesize'] ?? 20);
        
        $batch_filter_action_type = $params['action_type'] ?? 'all';
        $batch_filter_field_group = $params['field_group'] ?? 'all';
        $batch_filter_keyword = $params['keyword'] ?? '';
        $batch_risk_level = $params['risk_level'] ?? 'all';
        $batch_sort = $params['batch_sort'] ?? 'latest';
        
        $batch_action_options = api_import_batch_item_action_options();
        $batch_risk_options = api_import_batch_risk_level_options();
        $batch_sort_options = api_import_batch_list_sort_options();
        
        if (!array_key_exists($batch_filter_action_type, $batch_action_options)) {
            $batch_filter_action_type = 'all';
        }
        if (!array_key_exists($batch_filter_field_group, api_import_batch_item_field_group_options())) {
            $batch_filter_field_group = 'all';
        }
        if (!array_key_exists($batch_risk_level, $batch_risk_options)) {
            $batch_risk_level = 'all';
        }
        if (!array_key_exists($batch_sort, $batch_sort_options)) {
            $batch_sort = 'latest';
        }
        
        $batch = $batch_id ? api_import_batch_read($batch_id) : array();
        $batch_total_raw = api_import_batch_count(array());
        
        $batch_source = api_import_batch_find(array(), 1, max(20, $batch_total_raw));
        $batch_list_result = api_import_batch_list_prepare($batch_source, array(
            'risk_level' => $batch_risk_level,
        ), $batch_sort, $page, 20);
        
        $batchlist = $batch_list_result['items'];
        $batch_count = intval($batch_list_result['total']);
        $page = intval($batch_list_result['page']);
        
        $batch_overview_source = array_slice($batch_list_result['all_items'], 0, min(200, $batch_count));
        $batch_overview = api_import_batch_list_analyze($batch_overview_source);
        
        $batch_item_supports_result_status = api_import_batch_item_supports_field('result_status');
        if (!empty($batch_item_supports_result_status) && !empty($batchlist)) {
            foreach ($batchlist as $idx => $row) {
                if (intval($row['duplicate_num'] ?? 0) > 0) {
                    $batchlist[$idx] = api_import_batch_attach_skip_breakdown($row);
                }
            }
        }
        
        $batch_filters = array(
            'action_type' => $batch_filter_action_type,
            'field_group' => $batch_filter_field_group,
            'keyword' => $batch_filter_keyword,
        );
        
        $batch_item_result = !empty($batch) ? api_import_batch_item_paginate_filtered($batch_id, $batch_filters, $item_page, $item_pagesize, 5000) : array('items' => array(), 'total' => 0, 'page' => 1, 'pagesize' => $item_pagesize, 'totalpage' => 1);
        
        $batch_analysis_items = !empty($batch) ? api_import_batch_item_filter_result($batch_id, $batch_filters, 5000) : array();
        $batch_analysis = api_import_batch_item_analyze($batch_analysis_items);
        
        return array(
            'batch_id' => $batch_id,
            'page' => $page,
            'item_page' => $item_page,
            'item_pagesize' => $item_pagesize,
            'batch_filter_action_type' => $batch_filter_action_type,
            'batch_filter_field_group' => $batch_filter_field_group,
            'batch_filter_keyword' => $batch_filter_keyword,
            'batch_risk_level' => $batch_risk_level,
            'batch_sort' => $batch_sort,
            'batch_action_options' => $batch_action_options,
            'batch_risk_options' => $batch_risk_options,
            'batch_sort_options' => $batch_sort_options,
            'batch' => $batch,
            'batch_supports_updated_num' => api_import_batch_supports_updated_num(),
            'batch_item_supports_action_type' => api_import_batch_item_supports_field('action_type'),
            'batch_item_supports_result_status' => $batch_item_supports_result_status,
            'batch_item_supports_diff_fields' => api_import_batch_item_supports_field('diff_fields'),
            'batch_item_supports_update_scope' => api_import_batch_item_supports_field('update_scope'),
            'batch_item_supports_diff_snapshot' => api_import_batch_item_supports_field('diff_snapshot'),
            'batchlist' => $batchlist,
            'batch_count' => $batch_count,
            'batch_overview' => $batch_overview,
            'batch_field_group_options' => api_import_batch_item_field_group_options(),
            'safe_token' => bull_token_set($uid, 'admin_api_batch_rollback'),
            'batch_filters' => $batch_filters,
            'batch_items' => $batch_item_result['items'],
            'batch_items_total' => intval($batch_item_result['total']),
            'batch_items_page' => intval($batch_item_result['page']),
            'batch_items_pagesize' => intval($batch_item_result['pagesize']),
            'batch_items_totalpage' => intval($batch_item_result['totalpage']),
            'batch_analysis' => $batch_analysis,
        );
    }

    /**
     * 导出批次明细为CSV
     * 
     * @param int $batchId 批次ID
     * @param array $filters 过滤条件
     */
    public static function exportCsv(int $batchId, array $filters): void
    {
        api_import_batch_require_tables();
        $export_items = api_import_batch_item_filter_result($batchId, $filters, 5000);
        $export_rows = api_import_batch_item_export_rows($export_items);
        
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="batch_' . $batchId . '_audit.csv"');
        echo "\xEF\xBB\xBF";
        $fp = fopen('php://output', 'w');

        // 发布前检查修复（A-8）：CSV 公式注入中和——单元格以 = + - @ \t \r 开头时前置单引号
        // （导出内容含采集远端字段，防 Excel/WPS 打开即执行公式）
        $csvSafe = function ($v) {
            $v = is_scalar($v) ? (string)$v : '';
            if ('' !== $v && false !== strpos("=+-@\t\r", $v[0])) {
                $v = "'" . $v;
            }
            return $v;
        };
        if (!empty($export_rows)) {
            fputcsv($fp, array_map($csvSafe, array_keys($export_rows[0])));
            foreach ($export_rows as $row) {
                fputcsv($fp, array_map($csvSafe, $row));
            }
        } else {
            fputcsv($fp, array('动作', '结果', 'VID', 'CID', '名称', '差异字段', '更新范围', '前后值', '导入时间'));
        }
        fclose($fp);
        exit;
    }

    /**
     * 回滚批次
     * 
     * @param int $batchId 批次ID
     * @return array 回滚结果
     */
    public static function rollback(int $batchId): array
    {
        api_import_batch_require_tables();
        $result = api_import_batch_rollback($batchId);
        
        if (intval($result['code']) === 0) {
            admin_audit_log('api_fastdb_rollback', array(
                'batch_id' => $batchId, 
                'rollback_num' => intval($result['rollback_num'] ?? 0)
            ));
        }
        
        return $result;
    }
    /**
     * 批次主表完整表名（含库前缀）。db 连接未装载（未安装态）时回退默认前缀 nncms_。
     *
     * @return string 如 nncms_api_import_batch
     */
    public static function table()
    {
        $db = _SERVER('db') ?? null;
        return $db ? $db->tablepre . 'api_import_batch' : 'nncms_api_import_batch';
    }

    /**
     * 批次明细表完整表名（含库前缀），回退口径同 table()。
     *
     * @return string 如 nncms_api_import_batch_item
     */
    public static function itemTable()
    {
        $db = _SERVER('db') ?? null;
        return $db ? $db->tablepre . 'api_import_batch_item' : 'nncms_api_import_batch_item';
    }

    /**
     * 明细表是否含某列（DESCRIBE 探测，结果按列名进程内 memo）。
     * 用于兼容旧库缺新列的降级：表未就绪时直接判 FALSE，不做探测。
     *
     * @param string $field 列名（db_find_field 内部有白名单正则防反引号逃逸）
     * @return bool
     */
    public static function itemSupportsField($field)
    {
        static $cache = array();
        if (isset($cache[$field])) return $cache[$field];
        if (!api_import_batch_tables_ready()) {
            $cache[$field] = FALSE;
            return $cache[$field];
        }
        $cache[$field] = db_find_field(api_import_batch_item_table(), $field);
        return $cache[$field];
    }

    /**
     * 批次主表与明细表是否都已建表。
     *
     * @return bool 两张表都存在为 TRUE
     */
    public static function tablesReady()
    {
        return db_find_table(api_import_batch_table()) && db_find_table(api_import_batch_item_table());
    }

    /**
     * 未建表时给运维的提示文案。
     *
     * @return string
     */
    public static function tablesHint()
    {
        return '采集批次表不存在，请确认新装使用 install/install.sql（已集成 api_import_batch 表）或手动建表。';
    }

    /**
     * 批次主表是否含 updated_num 列（进程内 memo，NULL 表示尚未探测）。
     *
     * @return bool 存在为 TRUE；表未就绪或旧库缺列为 FALSE
     */
    public static function supportsUpdatedNum()
    {
        static $supported = NULL;
        if ($supported !== NULL) return $supported;
        if (!api_import_batch_tables_ready()) {
            $supported = FALSE;
            return $supported;
        }
        $supported = db_find_field(api_import_batch_table(), 'updated_num');
        return $supported;
    }

    /**
     * 写入批次主表前的列兼容过滤：旧库不支持 updated_num 时剥掉
     * updated_num / updated_num+ / updated_num-（自增/自减表达式键）三键，避免 DESCRIBE 缺列报错。
     *
     * @param mixed $update 待写入的更新数组
     * @return array 过滤后的数组；非数组入参返回空数组
     */
    public static function filterUpdate($update)
    {
        if (!is_array($update)) return array();
        if (!api_import_batch_supports_updated_num()) {
            unset($update['updated_num'], $update['updated_num+'], $update['updated_num-']);
        }
        return $update;
    }

    /**
     * 写入明细表前的列兼容过滤：逐列查 itemSupportsField()，
     * 剥离旧库不支持的 action_type/result_status/diff_fields/update_scope/diff_snapshot。
     *
     * @param mixed $row 待插入的明细行数组
     * @return array 过滤后的行；入参非数组时返回空数组
     */
    public static function itemFilterRow($row)
    {
        if (!is_array($row)) return array();
        if (!api_import_batch_item_supports_field('action_type')) {
            unset($row['action_type']);
        }
        if (!api_import_batch_item_supports_field('result_status')) {
            unset($row['result_status']);
        }
        if (!api_import_batch_item_supports_field('diff_fields')) {
            unset($row['diff_fields']);
        }
        if (!api_import_batch_item_supports_field('update_scope')) {
            unset($row['update_scope']);
        }
        if (!api_import_batch_item_supports_field('diff_snapshot')) {
            unset($row['diff_snapshot']);
        }
        return $row;
    }

    /**
     * 表存在性守卫：主表/明细表未建齐时以 message(-1, tablesHint()) 终止请求（fail-closed），
     * 防止后续裸 SQL 打到不存在的表上抛 PDO 异常。
     *
     * @return void 表就绪则静默返回
     */
    public static function requireTables()
    {
        if (!api_import_batch_tables_ready()) {
            message(-1, api_import_batch_tables_hint());
        }
    }

    /**
     * 批次主表插入（先经 filterUpdate 剥不支持列）。走 QueryBuilder 真·预编译路径。
     *
     * @param array      $arr 列名=>值 的插入数据
     * @param object|null $d  连接/从库选择器（PdoDriver 实例；NULL 用默认主库）
     * @return int|false 新行自增 ID；空数组入参返回 FALSE
     */
    public static function rawCreate($arr, $d = NULL)
    {
        $arr = api_import_batch_filter_update($arr);
        return \App\Repository\RepositoryFactory::table('api_import_batch', 'id')->insert($arr, $d);
    }

    /**
     * 按主键 id 更新批次主表一行（先经 filterUpdate 剥不支持列）。
     *
     * @param int|string  $id     批次主键
     * @param array       $update 列名=>值（+/- 后缀列为自增/自减表达式）
     * @param object|null $d      连接/从库选择器
     * @return int|false 影响行数；空条件或空更新返回 FALSE
     */
    public static function rawUpdate($id, $update, $d = NULL)
    {
        $update = api_import_batch_filter_update($update);
        return \App\Repository\RepositoryFactory::table('api_import_batch', 'id')->update(array('id' => $id), $update, $d);
    }

    /**
     * 按主键读批次主表单行（QueryBuilder first，SELECT 全列）。
     *
     * @param int|string  $id 批次主键
     * @param object|null $d  连接/从库选择器
     * @return array|null 命中行关联数组；不存在返回 NULL
     */
    public static function rawRead($id, $d = NULL)
    {
        return \App\Repository\RepositoryFactory::table('api_import_batch', 'id')->read($id, 'id', $d);
    }

    /**
     * 批次主表条件分页查询。
     *
     * @param array       $cond     where 条件（列名=>值，值含 +/- 后缀为算术表达式）
     * @param array       $orderby  列名=>方向（-1=DESC，1=ASC）
     * @param int         $page     页码（从 1 起）
     * @param int         $pagesize 每页条数
     * @param string      $key      非空则以该列值作结果数组键（默认 'id'）
     * @param array       $col      显式列清单；空数组=SELECT *（会被记 sql_star_select 留痕）
     * @param object|null $d        连接/从库选择器
     * @return array 行数组（$key 非空时按该键索引）；无结果返回空数组
     */
    public static function rawFind($cond = array(), $orderby = array('id' => -1), $page = 1, $pagesize = 20, $key = 'id', $col = array(), $d = NULL)
    {
        return \App\Repository\RepositoryFactory::table('api_import_batch', 'id')->find($cond, $orderby, $page, $pagesize, $key, $col, $d);
    }

    /**
     * 批次主表条件计数。
     *
     * @param array       $cond 条件；空=全表计数
     * @param object|null $d    连接/从库选择器
     * @return int 匹配行数
     */
    public static function rawCount($cond = array(), $d = NULL)
    {
        return \App\Repository\RepositoryFactory::table('api_import_batch', 'id')->count($cond, $d);
    }

    /**
     * 批次主表条件删除。QueryBuilder 无 where 会抛异常，故空条件时仓库层直接返回 FALSE 防全表删。
     *
     * @param array       $cond 删除条件（非空才生效）
     * @param object|null $d    连接/从库选择器
     * @return int|false 影响行数；空条件返回 FALSE
     */
    /**
     * 明细表单行插入（不剥列——调用方需自行保证列兼容）。
     *
     * @param array      $arr 列名=>值
     * @param object|null $d  连接/从库选择器
     * @return int|false 新行 ID；空数组返回 FALSE
     */
    /**
     * 明细表条件分页查询（$key 默认空串 ⇒ 结果按数字下标返回，非按主键索引）。
     *
     * @param array       $cond     where 条件
     * @param array       $orderby  列名=>方向
     * @param int         $page     页码
     * @param int         $pagesize 每页条数（默认 100）
     * @param string      $key      结果索引列；空=不重排
     * @param array       $col      显式列清单；空=SELECT *（留痕）
     * @param object|null $d        连接/从库选择器
     * @return array 行数组；无结果返回空数组
     */
    public static function itemRawFind($cond = array(), $orderby = array('id' => -1), $page = 1, $pagesize = 100, $key = '', $col = array(), $d = NULL)
    {
        return \App\Repository\RepositoryFactory::table('api_import_batch_item', 'id')->find($cond, $orderby, $page, $pagesize, $key, $col, $d);
    }

    /**
     * 明细表条件计数。
     *
     * @param array       $cond 条件；空=全表计数
     * @param object|null $d    连接/从库选择器
     * @return int
     */
    /**
     * 明细表条件删除；空条件返回 FALSE 防全表删。
     *
     * @param array       $cond 删除条件
     * @param object|null $d    连接/从库选择器
     * @return int|false 影响行数 / FALSE
     */
    public static function itemRawDelete($cond = array(), $d = NULL)
    {
        return \App\Repository\RepositoryFactory::table('api_import_batch_item', 'id')->delete($cond, $d);
    }

    /**
     * 明细表批量插入（单条多值 INSERT，bulkInsert）。
     *
     * @param array       $rows 行数组的数组
     * @param object|null $d    连接/从库选择器
     * @return int|false 插入行数；空数组返回 FALSE
     */
    public static function itemBigInsert($rows, $d = NULL)
    {
        return \App\Repository\RepositoryFactory::table('api_import_batch_item', 'id')->bigInsert($rows, $d);
    }

    /**
     * 建批次主表记录（对外入口）：先过 requireTables 建表守卫，再转发兼容壳
     * api_import_batch__create()（保留 model 层 hook 埋点）落到 rawCreate()。
     *
     * @param array $arr 列名=>值
     * @return int|false 新批次 ID / FALSE（空数组）
     */
    public static function create($arr)
    {
        api_import_batch_require_tables();
        return api_import_batch__create($arr);
    }

    /**
     * 按主键更新批次主表一行（守卫同 create，转 rawUpdate 并剥不支持列）。
     *
     * @param int|string $id     批次主键
     * @param array      $update 列名=>值
     * @return int|false 影响行数 / FALSE（空更新）
     */
    public static function update($id, $update)
    {
        api_import_batch_require_tables();
        return api_import_batch__update($id, $update);
    }

    /**
     * 读批次主表单行（守卫同 create，转 rawRead）。
     *
     * @param int|string $id 批次主键
     * @return array|null 命中行 / NULL（不存在）
     */
    public static function read($id)
    {
        api_import_batch_require_tables();
        return api_import_batch__read($id);
    }

    /**
     * 批次主表条件分页查询（固定 id 倒序、以 id 作结果键，转 rawFind）。
     *
     * @param array $cond     where 条件
     * @param int   $page     页码
     * @param int   $pagesize 每页条数
     * @return array 以批次 id 索引的行数组
     */
    public static function find($cond = array(), $page = 1, $pagesize = 20)
    {
        api_import_batch_require_tables();
        return api_import_batch__find($cond, array('id' => -1), $page, $pagesize, 'id');
    }

    /**
     * 批次主表条件计数（守卫同 create，转 rawCount）。
     *
     * @param array $cond 条件；空=全表计数
     * @return int
     */
    public static function count($cond = array())
    {
        api_import_batch_require_tables();
        return api_import_batch__count($cond);
    }

    /**
     * 批次列表内存预处理：逐行附加风险画像（attachRiskProfile，含 _risk_score 等派生键）
     * → 按 risk_level 过滤 → 按 $sort 排序（latest=倒序 id / risk_desc / skipped_desc / changed_desc，
     * 同分均以 id 倒序兜底）→ 切页。全量结果在 all_items 键返回，供上层做总览统计。
     * 注意是 PHP 侧分页，调用方须自行控制 $batches 规模（list() 上限 200）。
     *
     * @param array $batches   批次行数组
     * @param array $filters   目前仅消费 risk_level 键
     * @param string $sort     排序模式（见上）
     * @param int   $page      页码（越界自动钳到末页）
     * @param int   $pagesize  每页条数
     * @return array {items, all_items, total, page, pagesize, totalpage}
     */
    public static function listPrepare($batches, $filters = array(), $sort = 'latest', $page = 1, $pagesize = 20)
    {
        $page = max(1, intval($page));
        $pagesize = max(1, intval($pagesize));
        $risk_level = $filters['risk_level'] ?? 'all';
        $prepared = array();

        foreach ($batches as $batch) {
            $batch = api_import_batch_attach_risk_profile($batch);
            $level = api_import_batch_risk_level_key(intval($batch['_risk_score'] ?? 0));
            if ($risk_level !== 'all' && $level !== $risk_level) {
                continue;
            }
            $prepared[] = $batch;
        }

        usort($prepared, function ($a, $b) use ($sort) {
            if ($sort === 'risk_desc') {
                $cmp = intval($b['_risk_score'] ?? 0) <=> intval($a['_risk_score'] ?? 0);
                return $cmp !== 0 ? $cmp : (intval($b['id'] ?? 0) <=> intval($a['id'] ?? 0));
            }
            if ($sort === 'skipped_desc') {
                $cmp = intval($b['duplicate_num'] ?? 0) <=> intval($a['duplicate_num'] ?? 0);
                if ($cmp !== 0) return $cmp;
                return intval($b['_risk_score'] ?? 0) <=> intval($a['_risk_score'] ?? 0);
            }
            if ($sort === 'changed_desc') {
                $a_changed = intval($a['imported_num'] ?? 0) + intval($a['updated_num'] ?? 0);
                $b_changed = intval($b['imported_num'] ?? 0) + intval($b['updated_num'] ?? 0);
                $cmp = $b_changed <=> $a_changed;
                return $cmp !== 0 ? $cmp : (intval($b['id'] ?? 0) <=> intval($a['id'] ?? 0));
            }
            return intval($b['id'] ?? 0) <=> intval($a['id'] ?? 0);
        });

        $total = count($prepared);
        $totalpage = $total > 0 ? intval(ceil($total / $pagesize)) : 1;
        if ($page > $totalpage) {
            $page = $totalpage;
        }
        $offset = ($page - 1) * $pagesize;

        return array(
            'items' => array_slice($prepared, $offset, $pagesize),
            'all_items' => $prepared,
            'total' => $total,
            'page' => $page,
            'pagesize' => $pagesize,
            'totalpage' => $totalpage,
        );
    }

    /**
     * 批次总览聚合统计：累计完成/回滚批次数、导入/更新总量、平均风险分，
     * 并挑出变更最多/跳过最多/风险最高三个 TOP 批次（后两者经 attachSkipBreakdown
     * 追加跳过原因细分）。空入参返回全零骨架。
     *
     * @param array $batches 批次行数组（foreach 值拷贝，不回写调用方）
     * @return array {total_batches, finished_batches, rolled_back_batches, total_imported,
     *               total_updated, avg_risk_score, top_updated_batch, top_skipped_batch, top_risk_batch}
     */
    public static function listAnalyze($batches)
    {
        $stats = array(
            'total_batches' => count($batches),
            'finished_batches' => 0,
            'rolled_back_batches' => 0,
            'total_imported' => 0,
            'total_updated' => 0,
            'avg_risk_score' => 0,
            'top_updated_batch' => array(),
            'top_skipped_batch' => array(),
            'top_risk_batch' => array(),
        );
        if (empty($batches)) return $stats;

        $risk_sum = 0;
        $top_updated = NULL;
        $top_skipped = NULL;
        $top_risk = NULL;

        foreach ($batches as $batch) {
            $status = intval($batch['status'] ?? 0);
            $imported = max(0, intval($batch['imported_num'] ?? 0));
            $updated = max(0, intval($batch['updated_num'] ?? 0));
            $skipped = max(0, intval($batch['duplicate_num'] ?? 0));
            $risk = api_import_batch_risk_profile($batch);
            $risk_score = intval($risk['score'] ?? 0);

            if ($status === 1) $stats['finished_batches']++;
            if ($status === 2) $stats['rolled_back_batches']++;
            $stats['total_imported'] += $imported;
            $stats['total_updated'] += $updated;
            $risk_sum += $risk_score;

            $batch['_risk_score'] = $risk_score;
            $batch['_risk_label'] = $risk['label'] ?? api_import_batch_risk_label($risk_score);
            $batch['_risk_summary'] = $risk['summary'] ?? '';
            $batch['_risk_reasons'] = $risk['reasons'] ?? array();

            if ($top_updated === NULL || $imported + $updated > intval($top_updated['imported_num'] ?? 0) + intval($top_updated['updated_num'] ?? 0)) {
                $top_updated = $batch;
            }
            if ($top_skipped === NULL || $skipped > intval($top_skipped['duplicate_num'] ?? 0)) {
                $top_skipped = $batch;
            }
            if ($top_risk === NULL || $risk_score > intval($top_risk['_risk_score'] ?? 0)) {
                $top_risk = $batch;
            }
        }

        $stats['avg_risk_score'] = round($risk_sum / max(1, count($batches)), 2);
        $stats['top_updated_batch'] = $top_updated ?: array();
        $stats['top_skipped_batch'] = !empty($top_skipped) ? api_import_batch_attach_skip_breakdown($top_skipped) : array();
        $stats['top_risk_batch'] = !empty($top_risk) ? api_import_batch_attach_skip_breakdown($top_risk) : array();
        return $stats;
    }

    /**
     * 向批次追加明细行：逐行 itemFilterRow 剥旧库不支持列后 bigInsert 单语句批量写入。
     *
     * @param int|string $batch_id 批次主键（空则直接拒绝）
     * @param array      $rows     明细行数组
     * @return int|false 插入行数；入参为空或全部行被剥空返回 FALSE
     */
    public static function appendItems($batch_id, $rows)
    {
        if (empty($batch_id) || empty($rows)) return FALSE;
        api_import_batch_require_tables();
        $filtered_rows = array();
        foreach ($rows as $row) {
            $row = api_import_batch_item_filter_row($row);
            if (!empty($row)) {
                $filtered_rows[] = $row;
            }
        }
        if (empty($filtered_rows)) return FALSE;
        return api_import_batch_item_big_insert($filtered_rows);
    }

    /**
     * 标记批次收尾：写入 status + finish_date（$note 非空才附带 note）。
     * 未校验批次是否存在，直接按主键 update。
     *
     * @param int|string $batch_id 批次主键；空则拒绝
     * @param int        $status   收尾状态（默认 1=完成，2=回滚）
     * @param string     $note     备注，空串则不写该键
     * @return int|false 影响行数 / FALSE（batch_id 为空或主表无该键更新）
     */
    public static function markFinished($batch_id, $status = 1, $note = '')
    {
        if (empty($batch_id)) return FALSE;
        $update = array(
            'status' => intval($status),
            'finish_date' => time(),
        );
        if ($note !== '') {
            $update['note'] = $note;
        }
        return api_import_batch_update($batch_id, $update);
    }

    /**
     * 回滚一个采集批次（低层实现）：
     *  · 批次不存在 / status=2 已回滚 → 直接返回 code=-1（fail-closed，不做破坏性操作）；
     *  · 无明细行 → 仅置 status=2 并返回 rollback_num=0；
     *  · 有明细行 → 取 vid 集合（明细表支持 action_type 列时仅回滚本批 create 动作产生的行，
     *    update 动作影响的旧内容不还原），经 VideoService::deleteAll 删视频，删全部明细，
     *    置 status=2/rollback_date/rollback_num，最后清分类列表缓存。
     *
     * @param int|string $batch_id 批次主键
     * @return array {code:0|(-1), message:string, rollback_num?:int}
     */
    public static function rollbackRaw($batch_id)
    {
        api_import_batch_require_tables();
        $batch = api_import_batch_read($batch_id);
        if (empty($batch)) {
            return array('code' => -1, 'message' => '批次不存在');
        }
        if (intval($batch['status']) === 2) {
            return array('code' => -1, 'message' => '该批次已回滚');
        }
        $items = api_import_batch_item_find_by_batch($batch_id, 1, 100000);
        if (empty($items)) {
            api_import_batch_update($batch_id, array(
                'status' => 2,
                'rollback_date' => time(),
                'rollback_num' => 0,
                'note' => '批次无可回滚内容',
            ));
            return array('code' => 0, 'message' => '批次无可回滚内容', 'rollback_num' => 0);
        }
        $vids = array();
        foreach ($items as $item) {
            $vid = intval($item['vid'] ?? 0);
            if ($vid > 0) $vids[] = $vid;
        }
        $vids = array_values(array_unique($vids));
        if (api_import_batch_item_supports_field('action_type')) {
            $create_vids = array();
            foreach ($items as $item) {
                if (($item['action_type'] ?? 'create') === 'create') {
                    $vid = intval($item['vid'] ?? 0);
                    if ($vid > 0) $create_vids[] = $vid;
                }
            }
            $vids = array_values(array_unique($create_vids));
        }
        if (!empty($vids)) {
            // ⚠ 不再吞返回值（复核修正）：旧写法忽略 FALSE 仍把批次置 status=2「已回滚」⇒ 内容其实一条没删，
            //   而事后所有回滚入口都被 status=2 的 fail-closed 闸拦住（永久无法再滚）。现失败即如实返回，
            //   批次保持未回滚状态（明细不删、状态不改），管理员可修复后重试。
            if (FALSE === \App\Service\Content\VideoService::deleteAll($vids)) {
                xn_log('rollback aborted: VideoService::deleteAll returned FALSE, batch=' . $batch_id, 'api_batch_error');
                return array('code' => -1, 'message' => '回滚失败：内容未被删除（多为回收站快照写入失败），批次保持未回滚，请检查回收站表结构后重试');
            }
        }
        api_import_batch_item__delete(array('batch_id' => $batch_id));
        api_import_batch_update($batch_id, array(
            'status' => 2,
            'rollback_date' => time(),
            'rollback_num' => count($vids),
            'note' => '已回滚批次导入内容',
        ));
        cate_list_cache_delete();
        return array('code' => 0, 'message' => '回滚成功', 'rollback_num' => count($vids));
    }

}
