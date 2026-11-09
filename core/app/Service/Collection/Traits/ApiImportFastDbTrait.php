<?php
namespace App\Service\Collection\Traits;

/**
 * fastDb 快速采集族（从 ApiImportService 拆出，Trait 组合，消除 898 行神类）
 */
trait ApiImportFastDbTrait
{
    /** Read-only preview uses the same matching and play-update planner as normal collection. */
    public static function fastDbPreview(string $code, int $sample_limit = 12, string $update_scope = 'all'): array
    {
        $sample_limit = max(1, $sample_limit);
        $preview = ['total'=>0,'importable'=>0,'updatable'=>0,'unchanged'=>0,'unbound'=>0,
            'samples'=>['importable'=>[],'updatable'=>[],'unchanged'=>[],'unbound'=>[]], 'unbound_types'=>[]];
        $items = self::fastDbLoadList($code);
        foreach (\App\Service\Collection\ApiExecutionService::previewFastVideoData($items, $code, $update_scope) as $row) {
            $status = $row['status']; $preview['total']++; $preview[$status]++;
            if ($status === 'unbound' && $row['type_name'] !== '') $preview['unbound_types'][$row['type_name']] = $row['type_name'];
            if (count($preview['samples'][$status]) < $sample_limit) $preview['samples'][$status][] = $row;
        }
        $preview['unbound_types'] = array_values($preview['unbound_types']);
        return $preview;
    }

    /** The batch's saved policy is authoritative; client policy fields are compatibility-only. */
    public static function fastDbRun(string $code, int $page, $limit, int $batch_id = 0, string $update_mode = 'skip', string $update_scope = 'all'): array
    {
        return \App\Service\Collection\CollectionBatchStateService::withLock($batch_id, static function () use ($code, $page, $batch_id) {
            $batch = \App\Service\Collection\CollectionBatchStateService::batch($batch_id);
            $state = \App\Service\Collection\CollectionBatchStateService::read($batch_id);
            $replay = \App\Service\Collection\CollectionBatchStateService::validate($batch, $state, $code, $page);
            if ($replay !== null) return $replay;
            $items = self::fastDbLoadList($state['code']);
            $digest = hash('sha256', json_encode($items, JSON_THROW_ON_ERROR));
            if (isset($state['digest']) && !hash_equals($state['digest'], $digest)) throw new \RuntimeException('快速采集数据已变化，已导入内容保留；请重新创建批次');
            $state['digest'] = $digest;
            $limit = (int)$state['limit']; $total = count($items); $maxPage = max(1, (int)ceil($total / $limit));
            if ($page > $maxPage) throw new \RuntimeException('采集页码超出数据范围');
            $slice = array_slice($items, ($page - 1) * $limit, $limit);
            $receipt = [];
            // Warm schema capability checks before entering the content transaction.
            api_import_batch_supports_updated_num();
            foreach (['action_type','result_status','diff_fields','diff_snapshot','update_scope'] as $field) api_import_batch_item_supports_field($field);
            \App\Service\Collection\ApiExecutionService::importVideoData(['data'=>$slice], $code,
                ['mode'=>$state['mode'], 'scope'=>$state['scope']],
                static function (array $rows) use (&$receipt, $batch_id, $page, $maxPage, $total, $state) {
                    $counts = ['create'=>0,'update'=>0,'skip'=>0]; $items = []; $time = time();
                    foreach ($rows as $row) {
                        $counts[$row['action_type']]++;
                        // Legacy item tables cannot distinguish updates/skips from inserts during rollback.
                        if (!api_import_batch_item_supports_field('action_type') && $row['action_type'] !== 'create') continue;
                        $row['batch_id'] = $batch_id; $row['create_date'] = $time; $row['update_scope'] = $state['scope'];
                        $row['diff_fields'] = json_encode($row['diff_fields'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        $row['diff_snapshot'] = json_encode($row['diff_snapshot'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        $items[] = $row;
                    }
                    foreach (array_chunk($items, 500) as $chunk) {
                        if (api_import_batch_append_items($batch_id, $chunk) !== count($chunk)) throw new \RuntimeException('批次明细保存失败，本页已回滚，请重试');
                    }
                    $update = ['processed_num+'=>count($rows),'imported_num+'=>$counts['create'],'updated_num+'=>$counts['update'],
                        'duplicate_num+'=>$counts['skip'],'last_page'=>$page,'total_num'=>$total,'status'=>$page >= $maxPage ? 1 : 0,
                        'finish_date'=>$page >= $maxPage ? $time : 0];
                    if (!api_import_batch_update($batch_id, $update)) throw new \RuntimeException('批次进度保存失败，本页已回滚，请重试');
                    $receipt = ['status'=>$page >= $maxPage ? 'done' : 'progress','total'=>$total,'page'=>$page,'max_page'=>$maxPage,
                        'processed'=>count($rows),'inserted'=>$counts['create'],'updated'=>$counts['update'],'duplicates'=>$counts['skip'],
                        'batch_id'=>$batch_id,'next_page'=>$page + 1];
                    $state['receipt'] = $receipt;
                    \App\Service\Collection\CollectionBatchStateService::save($batch_id, $state);
                });
            return $receipt;
        });
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
            $res_json = http_get($fast_url, '', 30, 3, 'cloud');
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
     * @param array $existing_row 根据当前查重规则选中的库内 vod 行
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
