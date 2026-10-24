<?php
namespace App\Service\Collection\Traits;

/**
 * fastDb 快速采集族（从 ApiImportService 拆出，Trait 组合，消除 898 行神类）
 */
trait ApiImportFastDbTrait
{
    /**
     * 快速采集「预检」：全量拉取 fast 列表（fastDbLoadList），按名称比对库内已有 vod 行，
     * 统计 importable/updatable/unchanged/unbound 四类并各取最多 $sample_limit 条样本。
     * 纯只读不动库（update 行只做 diff 计算不应用）；后台入口有 method/CSRF/权限门禁（见 admin/route/api.php）。
     * @param string $code 采集源编码（对应 $conf['bind'][$code] 与 runtime/<code>.json）
     * @param int $sample_limit 每类样本上限（内部 max(1, intval())）
     * @param string $update_scope 更新范围，透传给 fastDbDiffFields；非法值在过滤层回落 'all'
     * @return array 键：total/importable/updatable/unchanged/unbound/samples(四类各一列表)/unbound_types
     */
    public static function fastDbPreview(string $code, int $sample_limit = 12, string $update_scope = 'all'): array
    {
        global $conf;
        ini_set('memory_limit', '-1');
        set_time_limit(0);

        $sample_limit = max(1, intval($sample_limit));
        $apicate = isset($conf['bind'][$code]) ? $conf['bind'][$code] : array();
        $arrlist = self::fastDbLoadList($code);
        $names = array();
        foreach ($arrlist as $item) {
            $name = isset($item['vod_name']) ? trim((string)$item['vod_name']) : '';
            if ($name !== '') {
                $names[$name] = $name;
            }
        }
        $existing_rows_map = self::fastDbFindExistingRowsMap(array_values($names));

        $preview = array(
            'total' => count($arrlist),
            'importable' => 0,
            'updatable' => 0,
            'unchanged' => 0,
            'unbound' => 0,
            'samples' => array(
                'importable' => array(),
                'updatable' => array(),
                'unchanged' => array(),
                'unbound' => array(),
            ),
            'unbound_types' => array(),
        );

        foreach ($arrlist as $item) {
            $name = isset($item['vod_name']) ? trim((string)$item['vod_name']) : '';
            $type_name = isset($item['type_name']) ? trim((string)$item['type_name']) : '';
            $cid = intval($apicate[$type_name] ?? 0);
            $row = array(
                'vod_name' => $name,
                'type_name' => $type_name,
                'vod_id' => intval($item['vod_id'] ?? 0),
                'cid' => $cid,
                'vod_time' => $item['vod_time'] ?? '',
            );

            if ($cid <= 0) {
                $preview['unbound']++;
                if ($type_name !== '') {
                    $preview['unbound_types'][$type_name] = $type_name;
                }
                if (count($preview['samples']['unbound']) < $sample_limit) {
                    $preview['samples']['unbound'][] = $row;
                }
                continue;
            }

            if ($name !== '' && isset($existing_rows_map[$name])) {
                $update_row = self::fastDbBuildUpdateRow($item, $cid);
                $diff_fields = self::fastDbDiffFields($existing_rows_map[$name], $update_row, $update_scope);
                $row['diff_fields'] = $diff_fields;
                $row['vid'] = intval($existing_rows_map[$name]['vid'] ?? 0);
                if (!empty($diff_fields)) {
                    $preview['updatable']++;
                    if (count($preview['samples']['updatable']) < $sample_limit) {
                        $preview['samples']['updatable'][] = $row;
                    }
                } else {
                    $preview['unchanged']++;
                    if (count($preview['samples']['unchanged']) < $sample_limit) {
                        $preview['samples']['unchanged'][] = $row;
                    }
                }
                continue;
            }

            $preview['importable']++;
            if (count($preview['samples']['importable']) < $sample_limit) {
                $preview['samples']['importable'][] = $row;
            }
        }

        $preview['unbound_types'] = array_values($preview['unbound_types']);
        return $preview;
    }

    /**
     * 快速采集单页执行：切出第 $page 页 → fastDbClassifySlice 分类并**就地写库**
     * （新增走逐行 insert 拿真实 vid；更新走 video_update=VideoService::update，副作用发生在分类阶段而非 Commit 阶段）
     * → 批次进度/明细落表。末页或空切片时把 fast_api=1 物理写回 core/config/config.php
     * （启动期配置，KV 注入前就要被侧栏可见性判定读取，属有意的物理写）。
     * 后台唯一入口 core/admin/route/api.php case 'fastdb'：POST + admin_require_bull_token('admin_api_fastdb') + 组权限 manageapi。
     * @param string $code 采集源编码
     * @param int $page 当前页码（从 1 起）
     * @param int|null $limit 每页条数；签名未声明类型，null 回落 5000（调用点 ApiImportService::import 声明传 int）
     * @param int $batch_id 采集批次 id（0=不写批次明细/进度）
     * @param string $update_mode 'update'=差异覆盖已有行，其余值一律按 'skip' 处理
     * @param string $update_scope 更新范围，非法值回落 'all'（白名单见 fastDbUpdateScopeOptions）
     * @return array status('done'|'progress')/total/page/max_page/processed/inserted/updated/duplicates/batch_id
     */
    public static function fastDbRun(string $code, int $page, $limit, int $batch_id = 0, string $update_mode = 'skip', string $update_scope = 'all'): array
    {
        global $conf;
        ini_set('memory_limit', '-1');
        set_time_limit(0);

        $duplicate_records = array();
        $update_records = array();
        $limit = $limit ?? 5000;
        $apicate = isset($conf['bind'][$code]) ? $conf['bind'][$code] : array();
        $resfile = APP_PATH . 'runtime/' . $code . '_echo.json';
        $arrlist = self::fastDbLoadList($code);
        $total = count($arrlist);
        $max_page = max(1, ceil($total / $limit));
        $update_mode = $update_mode === 'update' ? 'update' : 'skip';
        $update_scope = array_key_exists($update_scope, self::fastDbUpdateScopeOptions()) ? $update_scope : 'all';
        if ($page > $max_page) {
            // fast_api 为启动期一次性标记（侧栏入口判断读 $conf['fast_api']，见 AdminMenuService
            // 的 visible 判定；属 KV 注入前就要取用的启动期配置），保留物理写
            $replace = array();
            $replace['fast_api'] = 1;
            file_replace_var(\nncms_runtime_path_w('config.php'), $replace);
            if ($batch_id) {
                api_import_batch_mark_finished($batch_id, 1, '批次导入完成');
            }
            return self::fastDbDonePayload($total, $page, $max_page, $batch_id);
        }

        $start = ($page - 1) * $limit;
        $slice = array_slice($arrlist, $start, $limit);
        if (!$slice) {
            if ($batch_id) {
                api_import_batch_mark_finished($batch_id, 1, '批次导入完成');
            }
            return self::fastDbDonePayload($total, $page, $max_page, $batch_id);
        }

        $names = self::fastDbSliceNames($slice);
        $existing_rows_map = self::fastDbFindExistingRowsMap($names);

        $time = time();
        list($vod_rows, $update_records, $duplicate_records) = self::fastDbClassifySlice(
            $slice,
            $apicate,
            $existing_rows_map,
            $update_mode,
            $update_scope,
            $time
        );

        $count = self::fastDbCommitInserted($vod_rows, $time, $batch_id);
        self::fastDbCommitUpdates($update_records, $existing_rows_map, $time, $batch_id);
        self::fastDbCommitSkips($duplicate_records, $existing_rows_map, $time, $batch_id);

        if (!empty($duplicate_records)) {
            file_put_contents($resfile, json_encode($duplicate_records, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        if (!empty($update_records)) {
            file_put_contents(APP_PATH . 'runtime/' . $code . '_update.json', json_encode($update_records, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        self::fastDbUpdateBatchProgress($batch_id, $slice, $count, $update_records, $duplicate_records, $page, $max_page, $total, $time);

        return array(
            'status' => $page >= $max_page ? 'done' : 'progress',
            'total' => $total,
            'page' => $page,
            'max_page' => $max_page,
            'processed' => count($slice),
            'inserted' => $count,
            'updated' => count($update_records),
            'duplicates' => count($duplicate_records),
            'batch_id' => intval($batch_id),
        );
    }

    /**
     * fastDbRun 的「完成」零计数返回体（页码越界或空切片时复用），保证上层 status 判定一致。
     * @param int $total 全量条数
     * @param int $page 当前页码
     * @param int $max_page 总页数
     * @param int $batch_id 批次 id（返回值中会被 intval）
     * @return array 与 fastDbRun 同构、processed/inserted/updated/duplicates 全 0
     */
    private static function fastDbDonePayload($total, $page, $max_page, $batch_id): array
    {
        return array(
            'status' => 'done',
            'total' => $total,
            'page' => $page,
            'max_page' => $max_page,
            'processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'duplicates' => 0,
            'batch_id' => intval($batch_id),
        );
    }

    /**
     * 提取切片内的非空作品名列表：trim 后去空、去重、重排索引（作为查重 whereIn 的输入）。
     * @param array $slice 采集源行切片
     * @return array<int, string> vod_name 列表
     */
    private static function fastDbSliceNames(array $slice): array
    {
        return array_values(array_unique(array_filter(array_map(function ($item) {
            return isset($item['vod_name']) ? trim((string)$item['vod_name']) : '';
        }, $slice), function ($name) {
            return $name !== '';
        })));
    }

    /**
     * 逐行分类：unbound / unchanged / skipped_update / update / insert
     * @return array [vod_rows, update_records, duplicate_records]
     */
    private static function fastDbClassifySlice(array $slice, array $apicate, array $existing_rows_map, string $update_mode, string $update_scope, int $time): array
    {
        $vod_rows = array();
        $update_records = array();
        $duplicate_records = array();
        foreach ($slice as $v) {
            $name = isset($v['vod_name']) ? trim((string)$v['vod_name']) : '';
            $cid = intval($apicate[$v['type_name']] ?? 0);
            if ($cid <= 0) {
                $duplicate_records[] = array(
                    'vod_name' => $name,
                    'type_name' => $v['type_name'],
                    'check_time' => date('Y-m-d H:i:s'),
                    'status' => 'unbound',
                    'diff_snapshot' => array(
                        array(
                            'field' => 'type_name',
                            'label' => '分类',
                            'old' => '',
                            'new' => (string)($v['type_name'] ?? ''),
                        ),
                    ),
                );
                continue;
            }

            if ($name !== '' && isset($existing_rows_map[$name])) {
                $update_row = self::fastDbBuildUpdateRow($v, $cid);
                $update_row = self::fastDbFilterUpdateRowByScope($update_row, $update_scope);
                $diff_fields = self::fastDbDiffFields($existing_rows_map[$name], $update_row, 'all');
                $diff_snapshot = self::fastDbDiffSnapshot($existing_rows_map[$name], $update_row, 'all');
                if (empty($diff_fields) || $update_mode !== 'update') {
                    $duplicate_records[] = array(
                        'vod_name' => $name,
                        'type_name' => $v['type_name'],
                        'check_time' => date('Y-m-d H:i:s'),
                        'status' => empty($diff_fields) ? 'unchanged' : 'skipped_update',
                        'diff_fields' => $diff_fields,
                        'diff_snapshot' => $diff_snapshot,
                        'update_scope' => $update_scope,
                    );
                } else {
                    video_update(intval($existing_rows_map[$name]['vid']), $update_row);
                    $update_records[] = array(
                        'vod_name' => $name,
                        'type_name' => $v['type_name'],
                        'diff_fields' => $diff_fields,
                        'diff_snapshot' => $diff_snapshot,
                        'update_scope' => $update_scope,
                        'check_time' => date('Y-m-d H:i:s'),
                    );
                }
                continue;
            }

            $vod_rows[] = self::fastDbBuildVodRow($v, $cid, $time);
        }
        return array($vod_rows, $update_records, $duplicate_records);
    }

    /**
     * 提交新增：vod 批量插入 + vod_vid 关系 + 批次 create 明细
     * @return int 新增条数
     */
    private static function fastDbCommitInserted(array $vod_rows, int $time, int $batch_id): int
    {
        // 逐行插入拿真实 vid（此前 maxId 反推依赖连续+无并发，与 cron/rsync 并发时 vid/cid 错位；审计修复）
        $count = 0;
        $vid_rows = array();
        $vid_names = array();
        foreach ($vod_rows as $row) {
            $new_id = \App\Repository\RepositoryFactory::vod()->insert($row); \App\Service\Front\TypeService::bumpFacets();
            if ($new_id) {
                $count++;
                $vid_rows[] = array(
                    'vid' => intval($new_id),
                    'cid' => intval($row['cid']),
                );
                $vid_names[intval($new_id)] = $row['name']; // 仅供批次明细，不写入 vod_vid
            }
        }
        if ($vid_rows) {
            \App\Repository\RepositoryFactory::vodVid()->bigInsert($vid_rows);
            if ($batch_id && api_import_batch_tables_ready()) {
                $batch_item_rows = array();
                foreach ($vid_rows as $vid_row) {
                    $item_row = array(
                        'batch_id' => intval($batch_id),
                        'vid' => intval($vid_row['vid']),
                        'cid' => intval($vid_row['cid']),
                        'vod_name' => $vid_names[$vid_row['vid']] ?? '',
                        'create_date' => $time,
                    );
                    if (api_import_batch_item_supports_field('action_type')) {
                        $item_row['action_type'] = 'create';
                    }
                    if (api_import_batch_item_supports_field('result_status')) {
                        $item_row['result_status'] = 'create';
                    }
                    $batch_item_rows[] = $item_row;
                }
                api_import_batch_append_items($batch_id, $batch_item_rows);
            }
        }
        return $count;
    }

    /**
     * 把「更新」类记录写入采集批次明细（action_type/result_status='update'，diff 字段 JSON 序列化存列）。
     * 仅写批次表，不动 vod——实际更新已在 fastDbClassifySlice 内由 video_update 完成。
     * batch_id=0、记录为空、或批次表不支持 action_type 列时整体跳过（静默降级，不影响主流程）。
     * @param array $update_records 分类阶段产出的更新记录（含 vod_name/diff_fields/diff_snapshot/update_scope）
     * @param array $existing_rows_map 名称 => 库内 vod 行（fastDbFindExistingRowsMap 产物，取 vid/cid）
     * @param int $time 批次统一时间戳（create_date）
     * @param int $batch_id 批次 id
     */
    private static function fastDbCommitUpdates(array $update_records, array $existing_rows_map, int $time, int $batch_id): void
    {
        if ($batch_id && !empty($update_records) && api_import_batch_item_supports_field('action_type')) {
            $update_item_rows = array();
            foreach ($update_records as $record) {
                $existing_row = $existing_rows_map[$record['vod_name']] ?? array();
                $item_row = array(
                    'batch_id' => intval($batch_id),
                    'vid' => intval($existing_row['vid'] ?? 0),
                    'cid' => intval($existing_row['cid'] ?? 0),
                    'vod_name' => $record['vod_name'],
                    'create_date' => $time,
                );
                if (api_import_batch_item_supports_field('action_type')) {
                    $item_row['action_type'] = 'update';
                }
                if (api_import_batch_item_supports_field('result_status')) {
                    $item_row['result_status'] = 'update';
                }
                if (api_import_batch_item_supports_field('diff_fields')) {
                    $item_row['diff_fields'] = xn_json_encode($record['diff_fields']);
                }
                if (api_import_batch_item_supports_field('diff_snapshot')) {
                    $item_row['diff_snapshot'] = xn_json_encode($record['diff_snapshot']);
                }
                if (api_import_batch_item_supports_field('update_scope')) {
                    $item_row['update_scope'] = $record['update_scope'];
                }
                $update_item_rows[] = $item_row;
            }
            api_import_batch_append_items($batch_id, $update_item_rows);
        }
    }

    /**
     * 把「跳过」类记录（unbound=类型未绑定 / unchanged=无差异 / skipped_update=差异但模式为 skip）
     * 写入采集批次明细，result_status 存具体状态；unbound 行强制 cid=0。
     * batch_id=0、记录为空、或批次表不支持 result_status 列时整体跳过（静默降级）。
     * @param array $duplicate_records 分类阶段产出的跳过记录
     * @param array $existing_rows_map 名称 => 库内 vod 行（取 vid/cid；unbound 行通常无对应记录）
     * @param int $time 批次统一时间戳（create_date）
     * @param int $batch_id 批次 id
     */
    private static function fastDbCommitSkips(array $duplicate_records, array $existing_rows_map, int $time, int $batch_id): void
    {
        if ($batch_id && !empty($duplicate_records) && api_import_batch_item_supports_field('result_status')) {
            $skip_item_rows = array();
            foreach ($duplicate_records as $record) {
                $existing_row = $existing_rows_map[$record['vod_name']] ?? array();
                $status = (string)($record['status'] ?? 'unchanged');
                $item_row = array(
                    'batch_id' => intval($batch_id),
                    'vid' => intval($existing_row['vid'] ?? 0),
                    'cid' => intval($existing_row['cid'] ?? 0),
                    'vod_name' => (string)($record['vod_name'] ?? ''),
                    'create_date' => $time,
                    'result_status' => $status,
                );
                if (api_import_batch_item_supports_field('action_type')) {
                    $item_row['action_type'] = 'skip';
                }
                if ($status === 'unbound') {
                    $item_row['cid'] = 0;
                }
                if (!empty($record['diff_fields']) && api_import_batch_item_supports_field('diff_fields')) {
                    $item_row['diff_fields'] = xn_json_encode($record['diff_fields']);
                }
                if (!empty($record['diff_snapshot']) && api_import_batch_item_supports_field('diff_snapshot')) {
                    $item_row['diff_snapshot'] = xn_json_encode($record['diff_snapshot']);
                }
                if (!empty($record['update_scope']) && api_import_batch_item_supports_field('update_scope')) {
                    $item_row['update_scope'] = $record['update_scope'];
                }
                $skip_item_rows[] = $item_row;
            }
            api_import_batch_append_items($batch_id, $skip_item_rows);
        }
    }

    /**
     * 累加更新批次主表进度：计数字段用 '+' 后缀自增（QueryBuilder update 列名后缀语义），
     * last_page/total_num/status/finish_date 覆盖写；page>=max_page 时置 status=1 并写 finish_date。
     * updated_num 仅在批次表支持该列时写入。batch_id=0 或批次表未就绪时直接返回。
     * @param int $batch_id 批次 id
     * @param array $slice 本行处理的切片（用 count 计 processed_num）
     * @param int $count 本批新增条数（fastDbCommitInserted 返回）
     * @param array $update_records 更新记录（用 count 计 updated_num）
     * @param array $duplicate_records 跳过记录（用 count 计 duplicate_num）
     * @param int $page 当前页码
     * @param int $max_page 总页数
     * @param int $total 全量条数
     * @param int $time 批次统一时间戳
     */
    private static function fastDbUpdateBatchProgress(int $batch_id, array $slice, int $count, array $update_records, array $duplicate_records, int $page, int $max_page, int $total, int $time): void
    {
        if ($batch_id && api_import_batch_tables_ready()) {
            $batch_update = array(
                'processed_num+' => count($slice),
                'imported_num+' => $count,
                'duplicate_num+' => count($duplicate_records),
                'last_page' => intval($page),
                'total_num' => intval($total),
                'status' => ($page >= $max_page ? 1 : 0),
                'finish_date' => ($page >= $max_page ? $time : 0),
            );
            if (api_import_batch_supports_updated_num()) {
                $batch_update['updated_num+'] = count($update_records);
            }
            api_import_batch_update($batch_id, $batch_update);
        }
    }

    /**
     * 读取采集源的快速入库数据列表：优先本地缓存 runtime/<code>.json；缺失时经商店通道
     * （fast_down 或 {cloud_api}fast/<code>.json）http_get 下载，**仅解析出 list 才落盘**
     * （防 404 HTML 被永久缓存的自锁，注释在案）。cloud_api 未配置 = fail-closed 直接报错。
     * @param string $code 采集源编码；限定 [a-zA-Z0-9_-]+（防路径穿越），非法即 message(-1) 中断
     * @return array<int, array> 采集源行列表（vod_id/vod_name/type_name/vod_time 等接口字段）
     *         失败路径均经 message(-1, ...) 中断请求，正常返回必为非空 list
     */
    public static function fastDbLoadList(string $code): array
    {
        // code 仅允许安全字符，防止路径穿越 runtime/{code}.json
        if (!preg_match('#^[a-zA-Z0-9_\-]+$#', $code)) {
            message(-1, '非法的采集源标识');
        }
        $conffile = APP_PATH . 'runtime/' . $code . '.json';
        // 修复：统一走 cloudPayload()——商店返回**不加密**（原先的 core_decrypt 在此恒返空）。
        // 缺省地址为**商店主机** `{cloud_api}fast/<code>.json`（域名来自配置项 cloud_host）。
        $cb = \App\Service\System\CloudOpsService::storeBase();
        $arrlist = \App\Service\System\CloudOpsService::cloudPayload()['api'] ?? array();
        if (!empty($arrlist[$code]['fast_down'])) {
            $fast_url = \App\Service\System\CloudOpsService::storeUrl($arrlist[$code]['fast_down']);
        } else {
            $fast_url = ('' !== $cb) ? $cb . 'fast/' . $code . '.json' : '';
        }
        if ('' === $fast_url) {
            message(-1, '未配置云端地址（cloud_api），无法获取快速入库数据');
        }
        if (!is_file($conffile)) {
            $res_json = http_get($fast_url);
            // 修复（自锁面）：原实现把**任何**响应体直接落盘 runtime/<code>.json，
            // 且该文件无 TTL、只在 !is_file 时重取 ⇒ 商店缺 /fast/<code>.json 时（实测 404）会把
            // 404 HTML 永久缓存下来，之后永远解析失败、必须手工删文件才能恢复。
            // 现只落盘**可解析出 list 的 JSON**，否则直接报错且不写盘（下一次请求仍会重试）。
            $probe = xn_json_decode((string)$res_json);
            if (!is_array($probe) || empty($probe['list'])) {
                message(-1, "该采集源未提供快速入库数据（{$fast_url} 无有效 list）——请改用「采集」，或联系商店侧上架 fast 数据");
            }
            file_put_contents($conffile, $res_json);
        }
        $arr = xn_json_decode(file_get_contents($conffile));
        if (empty($arr['list'])) {
            message(-1, "请联系客服处理无法获取 {$code} json数据");
        }
        return $arr['list'];
    }

    /**
     * 批量查回vod 表同名已有行：whereIn 按 500 个名字分块（防单请求大 IN，CLAUDE.md「开发约定·远程下载/出网」口径），
     * 显式列清单（不用 SELECT *）。
     * @param array $names 作品名列表（fastDbSliceNames 产物为**源站原文 trim**，未做入库转义；
     *        与 vod.name 的转义存储形态不同域，含特殊字符标题会查重不中——见批次汇报）
     * @return array<string, array> 名称 => vod 行；同名多行时后读到的行覆盖前行；无命中/空输入返回空数组
     */
    public static function fastDbFindExistingRowsMap(array $names): array
    {
        $rows_map = array();
        if (empty($names)) return $rows_map;

        $fields = array(
            'vid', 'cid', 'name', 'uptime', 'vod_id', 'en', 'letter', 'pic',
            'actor', 'director', 'writer', 'blurb', 'remarks', 'area', 'lang',
            'year', 'douban_id', 'douban_score', 'content', 'play_from',
            'play_server', 'play_note', 'play_url'
        );
        $chunks = array_chunk($names, 500);
        foreach ($chunks as $chunk) {
            if (empty($chunk)) continue;
            $rows = \App\Repository\RepositoryFactory::vod()->builder()->select($fields)->whereIn('name', $chunk)->get();
            if (empty($rows)) continue;
            foreach ($rows as $row) {
                if (!empty($row['name'])) {
                    $rows_map[$row['name']] = $row;
                }
            }
        }
        return $rows_map;
    }

    /**
     * 把采集源行组装成可写入 vod 主表的完整行。
     * 转义边界（CLAUDE.md「安全红线·转义分层」，模板裸 echo 的红线落点）：文本字段**入库前**统一
     * htmlspecialchars(ENT_QUOTES)；pic 仅接受 http(s) 协议并 xn_url_strip；
     * play_* 三字段经 xn_url_strip；douban_score 钳位 0~9.9；letter 取英文名首字母大写。
     * @param array $v 采集源行（vod_* 键名；签名未声明类型，按数组偏移使用）
     * @param int|string $cid 本地分类 id（内部 intval）
     * @param int $time create_date 时间戳（0=取当前 time()）
     * @return array 与 vod 表列对齐的完整行（views/sticky/tag 等系统列给默认值）
     */
    public static function fastDbBuildVodRow($v, $cid, $time = 0): array
    {
        $time = $time ?: time();
        $en = htmlspecialchars($v['vod_en'] ?? '', ENT_QUOTES);
        // 按 vod 列宽截断（防 Data too long；update 行复用本函数，截断后 diff 才不会每次都判「有变化」）
        return \App\Service\Content\VideoService::fitColumns(array(
            'cid'          => intval($cid),
            'views'        => 0,
            'sticky'       => 0,
            'create_date'  => $time,
            'alias'        => '',
            'tag'          => '',
            'status'       => 0,
            'uptime'       => $v['vod_time'] ?? '',
            'vod_id'       => intval($v['vod_id'] ?? 0),
            'name'         => htmlspecialchars($v['vod_name'] ?? '', ENT_QUOTES),
            'sub'          => '',
            'en'           => $en,
            'letter'       => strtoupper(substr($en, 0, 1)),
            'pic'          => (preg_match('#^https?://#i', (string)($v['vod_pic'] ?? '')) ? xn_url_strip($v['vod_pic']) : ''),
            'pic_slide'    => '',
            'actor'        => htmlspecialchars($v['vod_actor'] ?? '', ENT_QUOTES),
            'director'     => htmlspecialchars($v['vod_director'] ?? '', ENT_QUOTES),
            'writer'       => htmlspecialchars($v['vod_writer'] ?? '', ENT_QUOTES),
            'behind'       => '',
            'blurb'        => htmlspecialchars($v['vod_blurb'] ?? '', ENT_QUOTES),
            'remarks'      => htmlspecialchars($v['vod_remarks'] ?? '', ENT_QUOTES),
            'pubdate'      => '',
            'total'        => 0,
            'serial'       => '0',
            'area'         => htmlspecialchars($v['vod_area'] ?? '', ENT_QUOTES),
            'lang'         => htmlspecialchars($v['vod_lang'] ?? '', ENT_QUOTES),
            'year'         => intval($v['vod_year'] ?? 0),
            'version'      => '',
            'level'        => 0,
            'douban_id'    => intval($v['vod_douban_id'] ?? 0),
            'douban_score' => min(9.9, max(0, floatval($v['vod_douban_score'] ?? 0))),
            'content'      => htmlspecialchars($v['vod_content'] ?? '', ENT_QUOTES),
            'play_from'    => $v['vod_play_from'] ?? '',
            'play_server'  => xn_url_strip($v['vod_play_server'] ?? ''),
            'play_note'    => xn_url_strip($v['vod_play_note'] ?? ''),
            'play_url'     => xn_url_strip($v['vod_play_url'] ?? ''),
            'cup'          => 0,
            'favorites'    => 0,
            'likes'        => 0,
        ));
    }

    /**
     * 更新场景专用行：复用 fastDbBuildVodRow（含同套入库转义）后，只保留 21 个允许被采集覆盖的列
     * （剔除 views/create_date/status/likes 等系统/统计列，避免覆盖回退本地数据）。
     * @param array $v 采集源行（vod_* 键名；签名未声明类型，按数组偏移使用）
     * @param int|string $cid 本地分类 id
     * @return array 可安全 update 到已有 vid 的列子集
     */
    public static function fastDbBuildUpdateRow($v, $cid): array
    {
        $row = self::fastDbBuildVodRow($v, $cid, time());
        return array(
            'cid'          => $row['cid'],
            'uptime'       => $row['uptime'],
            'vod_id'       => $row['vod_id'],
            'en'           => $row['en'],
            'letter'       => $row['letter'],
            'pic'          => $row['pic'],
            'actor'        => $row['actor'],
            'director'     => $row['director'],
            'writer'       => $row['writer'],
            'blurb'        => $row['blurb'],
            'remarks'      => $row['remarks'],
            'area'         => $row['area'],
            'lang'         => $row['lang'],
            'year'         => $row['year'],
            'douban_id'    => $row['douban_id'],
            'douban_score' => $row['douban_score'],
            'content'      => $row['content'],
            'play_from'    => $row['play_from'],
            'play_server'  => $row['play_server'],
            'play_note'    => $row['play_note'],
            'play_url'     => $row['play_url'],
        );
    }

    /**
     * 更新范围选项：update_scope 取值 => 中文标签，兼作 fastDbRun 中 scope 合法值的白名单。
     * @return array<string, string> 如 'all'=>'全部差异字段'
     */
    public static function fastDbUpdateScopeOptions(): array
    {
        return array(
            'all' => '全部差异字段',
            'play' => '仅播放器相关',
            'content' => '仅封面与简介资料',
            'meta' => '仅分类与基础资料',
        );
    }

    /**
     * 按更新范围抽取待比对/待写入列：play=播放器相关，content=封面简介资料，meta=分类与基础资料；
     * 'all' 的列集合直接取 fastDbDiffLabels 的键（新增可比对列需同步两处）。
     * @param array $update_row fastDbBuildUpdateRow 产物
     * @param string $update_scope 范围键；未知的取值回落 'all'
     * @return array 仅含该范围列的 update_row 子集（不存在的列跳过）
     */
    public static function fastDbFilterUpdateRowByScope(array $update_row, string $update_scope): array
    {
        $scopes = array(
            'all' => array_keys(self::fastDbDiffLabels()),
            'play' => array('uptime', 'play_from', 'play_server', 'play_note', 'play_url'),
            'content' => array('pic', 'actor', 'director', 'writer', 'blurb', 'remarks', 'content'),
            'meta' => array('cid', 'vod_id', 'en', 'letter', 'area', 'lang', 'year', 'douban_id', 'douban_score'),
        );
        $update_scope = isset($scopes[$update_scope]) ? $update_scope : 'all';
        $filtered = array();
        foreach ($scopes[$update_scope] as $field) {
            if (array_key_exists($field, $update_row)) {
                $filtered[$field] = $update_row[$field];
            }
        }
        return $filtered;
    }

    /**
     * 差异字段名清单（中文标签）：先把 $update_row 按 $update_scope 过滤，再逐列比较
     * （int 值按 intval 比，其余按 trim 后的字符串比）。
     * @param array $existing_row 库内 vod 行（fastDbFindExistingRowsMap 的值）
     * @param array $update_row 采集侧组装行（未过滤也可，内部会按 scope 过滤）
     * @param string $update_scope 比较范围
     * @return array<int, string> 有差异字段的中文标签列表（无差异=空数组）
     */
    public static function fastDbDiffFields(array $existing_row, array $update_row, string $update_scope = 'all'): array
    {
        $labels = self::fastDbDiffLabels();
        $update_row = self::fastDbFilterUpdateRowByScope($update_row, $update_scope);
        $diff_fields = array();
        foreach ($update_row as $field => $value) {
            $old_value = $existing_row[$field] ?? '';
            if (is_int($value)) {
                $new_normalized = intval($value);
                $old_normalized = intval($old_value);
            } else {
                $new_normalized = trim((string)$value);
                $old_normalized = trim((string)$old_value);
            }
            if ($new_normalized !== $old_normalized) {
                $diff_fields[] = $labels[$field] ?? $field;
            }
        }
        return $diff_fields;
    }

    /**
     * 差异快照：与 fastDbDiffFields 同套比较逻辑，但输出每字段的 field/label/old/new 四元组
     * （old/new 拍平成 string 存批次明细，供后台差异展示与回滚参考）。
     * @param array $existing_row 库内 vod 行
     * @param array $update_row 采集侧组装行
     * @param string $update_scope 比较范围
     * @return array<int, array{field:string,label:string,old:string,new:string}> 差异明细；无差异为空数组
     */
    public static function fastDbDiffSnapshot(array $existing_row, array $update_row, string $update_scope = 'all'): array
    {
        $labels = self::fastDbDiffLabels();
        $update_row = self::fastDbFilterUpdateRowByScope($update_row, $update_scope);
        $snapshot = array();
        foreach ($update_row as $field => $value) {
            $old_value = $existing_row[$field] ?? '';
            if (is_int($value)) {
                $new_normalized = intval($value);
                $old_normalized = intval($old_value);
            } else {
                $new_normalized = trim((string)$value);
                $old_normalized = trim((string)$old_value);
            }
            if ($new_normalized === $old_normalized) {
                continue;
            }
            $snapshot[] = array(
                'field' => $field,
                'label' => $labels[$field] ?? $field,
                'old' => (string)$old_value,
                'new' => (string)$value,
            );
        }
        return $snapshot;
    }

    /**
     * 可比对列名 => 中文标签映射；其键集合同时定义 scope 'all' 的列全集（fastDbFilterUpdateRowByScope 取 array_keys）。
     * @return array<string, string> 如 'play_url'=>'播放地址'
     */
    public static function fastDbDiffLabels(): array
    {
        return array(
            'cid' => '分类',
            'uptime' => '更新时间',
            'vod_id' => '资源站ID',
            'en' => '英文名',
            'letter' => '首字母',
            'pic' => '封面',
            'actor' => '演员',
            'director' => '导演',
            'writer' => '编剧',
            'blurb' => '简介',
            'remarks' => '备注',
            'area' => '地区',
            'lang' => '语言',
            'year' => '年份',
            'douban_id' => '豆瓣ID',
            'douban_score' => '豆瓣分',
            'content' => '内容',
            'play_from' => '播放来源',
            'play_server' => '播放服务器',
            'play_note' => '播放备注',
            'play_url' => '播放地址',
        );
    }
}
