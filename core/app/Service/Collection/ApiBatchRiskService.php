<?php
namespace App\Service\Collection;

/**
 * 采集批次风险评估域
 * 承接 model/api_import_batch.php 的 risk 系列函数（兼容壳转发入口，实现迁移至此）
 */
class ApiBatchRiskService
{
    /** 后台批次列表「风险等级」筛选下拉（all/high/medium/low => 中文）。等级键口径与 levelKey() 一致，改阈值要同步这里。@return array 等级键=>文案 */
    public static function riskLevelOptions(): array
    {
        return array(
            'all' => '全部风险',
            'high' => '高风险',
            'medium' => '中风险',
            'low' => '低风险',
        );
    }

    /** 后台批次列表排序方式下拉（latest/risk_desc/skipped_desc/changed_desc => 中文），键值供 ApiBatchService::listPrepare() 的 usort 分支选择（内存排序，不是 SQL ORDER BY）。@return array 排序键=>文案 */
    public static function listSortOptions(): array
    {
        return array(
            'latest' => '最新创建',
            'risk_desc' => '风险最高优先',
            'skipped_desc' => '跳过最多优先',
            'changed_desc' => '变更量最高优先',
        );
    }

    /** 给批次行挂风险画像：profile() 后追加 _risk_profile/_risk_score/_risk_label/_risk_summary/_risk_reasons 五个派生键并**返回新数组**（值传递，不回写入参；列表视图按这批键渲染）。入参为空或非数组原样返回；label 缺失时按 score 现算，不会给出空等级。@param array $batch 批次行 @return array|mixed 挂好画像的批次行；非数组入参为原值（类型不定） */
    public static function attachRiskProfile($batch)
    {
        if (empty($batch) || !is_array($batch)) {
            return $batch;
        }
        $risk = self::profile($batch);
        $batch['_risk_profile'] = $risk;
        $batch['_risk_score'] = intval($risk['score'] ?? 0);
        $batch['_risk_label'] = $risk['label'] ?? self::label($batch['_risk_score']);
        $batch['_risk_summary'] = $risk['summary'] ?? '';
        $batch['_risk_reasons'] = $risk['reasons'] ?? array();
        return $batch;
    }

    /** 资源站 code → 展示元数据：在 CloudOpsService::codes() 的清单里查 code，命中返回 {code,name,type}；未命中、清单结构不可用（未配 cloud_api 或缓存空 ⇒ codes() 返回非数组）时返回 {code,name=code}，即**不编造站名**。清单来源形态优先取 list，退回 api（两者结构不一致，取先到的一档）。⚠ codes() 可能出网/读缓存，只在后台批次详情调用。@param string $code 资源站编码 @return array {code,name,type?}——未命中分支没有 type 键，消费侧须用 ?? 兜底 */
    public static function sourceMeta($code)
    {
        $rescode = \App\Service\System\CloudOpsService::codes();
        if (!is_array($rescode)) return array('code' => $code, 'name' => $code);
        $lists = array();
        if (!empty($rescode['list']) && is_array($rescode['list'])) {
            $lists = $rescode['list'];
        } elseif (!empty($rescode['api']) && is_array($rescode['api'])) {
            $lists = $rescode['api'];
        }
        foreach ($lists as $item) {
            if (($item['code'] ?? '') === $code) {
                return array(
                    'code' => $code,
                    'name' => $item['name'] ?? $code,
                    'type' => $item['type'] ?? '',
                );
            }
        }
        return array('code' => $code, 'name' => $code);
    }

    /** 取批次风险分（profile() 的 score 分量，0-100）。整个画像算一遍只为拿一个数，批量场景请直接调 profile() 复用结果。@param array $batch 批次行 @return int 签名声明 : int */
    public static function score($batch): int
    {
        $risk = self::profile($batch);
        return intval($risk['score'] ?? 0);
    }

    /** 风险分 → 中文等级：>=70「高」、>=35「中」、否则「低」。与 levelKey() 是同一组阈值的两种输出形态（文案 vs 键），调阈值两处都要改。@param int $score 0-100 风险分（无类型声明，实参来自 profile() 的 int） @return string */
    public static function label($score): string
    {
        if ($score >= 70) return '高';
        if ($score >= 35) return '中';
        return '低';
    }

    /**
     * 批次风险画像（列表徽章、下钻、建议的共同底座）。
     *
     * 计分口径（三个分量都只认批次汇总行的既有计数列，不查明细）：
     *  · skip_points   = min(60, round(跳过率×100))，跳过率 = duplicate_num / processed_num；
     *  · rollback_points = status==2（已回滚）记 30；
     *  · update_points = min(10, round(更新占比×20))，更新占比 = updated_num / processed_num；
     *  · score = min(100, 三者之和)；processed_num<=0 时两个比率都记 0（不除零）。
     *
     * 同时产出 reasons（中文描述 + 分量）、items（贡献项：按 points 定色 + 下钻参数 + 占比 ratio）、
     * dominant_key/dominant_label（分量最大项，驱动 suggestions()）与 summary（前两条 reason 拼接）。
     * 纯函数：不查库、不写缓存、无副作用；批次行缺列按 0 处理，因此**未跑过导入的空批次也会返回完整骨架**。
     *
     * @param array $batch 批次行（读 processed_num/duplicate_num/updated_num/status/id）
     * @return array {score,label,summary,reasons,primary_reason,items,dominant_key,dominant_label,
     *                processed,skipped,updated,skip_ratio,update_ratio,skip_points,rollback_points,update_points}
     */
    public static function profile($batch): array
    {
        $processed = max(0, intval($batch['processed_num'] ?? 0));
        $skipped = max(0, intval($batch['duplicate_num'] ?? 0));
        $rolled_back = intval($batch['status'] ?? 0) === 2 ? 1 : 0;
        $updated = max(0, intval($batch['updated_num'] ?? 0));
        $skip_ratio = $processed > 0 ? ($skipped / $processed) : 0;
        $update_ratio = $processed > 0 ? ($updated / $processed) : 0;
        $skip_points = min(60, intval(round($skip_ratio * 100)));
        $rollback_points = $rolled_back ? 30 : 0;
        $update_points = min(10, intval(round($update_ratio * 20)));
        $score = min(100, $skip_points + $rollback_points + $update_points);
        $reasons = array();
        $risk_items = array();
        $dominant_key = '';
        $dominant_label = '';

        if ($skip_points > 0) {
            $risk_items[] = array(
                'key' => 'skip_ratio',
                'label' => '跳过率',
                'points' => $skip_points,
            );
            $reasons[] = array(
                'key' => 'skip_ratio',
                'points' => $skip_points,
                'text' => ($skip_ratio >= 0.5 ? '跳过率高' : ($skip_ratio >= 0.2 ? '跳过率偏高' : '存在跳过'))
                    . '（' . round($skip_ratio * 100, 1) . '%，' . $skipped . '/' . max(1, $processed) . '）',
            );
        }
        if ($rollback_points > 0) {
            $risk_items[] = array(
                'key' => 'rolled_back',
                'label' => '已回滚',
                'points' => $rollback_points,
            );
            $reasons[] = array(
                'key' => 'rolled_back',
                'points' => $rollback_points,
                'text' => '该批次已执行回滚',
            );
        }
        if ($update_points > 0) {
            $risk_items[] = array(
                'key' => 'update_ratio',
                'label' => '更新占比',
                'points' => $update_points,
            );
            $reasons[] = array(
                'key' => 'update_ratio',
                'points' => $update_points,
                'text' => ($update_ratio >= 0.4 ? '更新占比高' : '存在更新写入')
                    . '（' . round($update_ratio * 100, 1) . '%，' . $updated . '/' . max(1, $processed) . '）',
            );
        }

        if (empty($reasons)) {
            $summary = '本批次以新增为主，暂无明显风险项';
        } else {
            $summary_parts = array();
            foreach (array_slice($reasons, 0, 2) as $reason) {
                $summary_parts[] = $reason['text'];
            }
            $summary = implode('；', $summary_parts);
        }

        foreach ($reasons as $idx => $reason) {
            $reasons[$idx] = self::reasonMeta($batch, $reason);
        }

        if (!empty($risk_items) && $score > 0) {
            $dominant_points = -1;
            foreach ($risk_items as $idx => $item) {
                $risk_items[$idx]['ratio'] = round($item['points'] / max(1, $score) * 100, 1);
                $risk_items[$idx] = self::itemMeta($batch, $risk_items[$idx]);
                if (intval($item['points']) > $dominant_points) {
                    $dominant_points = intval($item['points']);
                    $dominant_key = $item['key'];
                    $dominant_label = $item['label'];
                }
            }
        }

        $primary_reason = !empty($reasons) ? $reasons[0] : array();

        return array(
            'score' => $score,
            'label' => self::label($score),
            'summary' => $summary,
            'reasons' => $reasons,
            'primary_reason' => $primary_reason,
            'items' => $risk_items,
            'dominant_key' => $dominant_key,
            'dominant_label' => $dominant_label,
            'processed' => $processed,
            'skipped' => $skipped,
            'updated' => $updated,
            'skip_ratio' => $skip_ratio,
            'update_ratio' => $update_ratio,
            'skip_points' => $skip_points,
            'rollback_points' => $rollback_points,
            'update_points' => $update_points,
        );
    }

    /** 风险贡献项的下钻入口元数据：key=update_ratio ⇒「查看更新明细」+action_type=update；skip_ratio ⇒「查看跳过明细」+action_type=skip；rolled_back ⇒ 用传入文案（列表徽章默认「查看回滚批次」、itemMeta 传「查看批次详情」）且不带 action 过滤。$batch_id<=0 或未知 key ⇒ 返回 {drill_text:'',drill_params:[]} 空骨架（视图据此不渲染链接）。参数含批次明细页全套 query 键，供后台 url() 拼装。@param int $batch_id @param string $key 贡献项键 @param string $rolled_back_text 仅 rolled_back 分支使用的文案 @return array {drill_text:string, drill_params:array} */
    public static function drillMeta($batch_id, $key, $rolled_back_text = '查看回滚批次'): array
    {
        $meta = array('drill_text' => '', 'drill_params' => array());
        $batch_id = intval($batch_id);
        if ($batch_id <= 0) return $meta;

        $base_params = array(
            'batch_id' => $batch_id,
            'page' => 1,
            'item_page' => 1,
            'item_pagesize' => 20,
            'action_type' => 'all',
            'field_group' => 'all',
            'keyword' => '',
        );

        if ($key === 'update_ratio') {
            $meta['drill_text'] = '查看更新明细';
            $meta['drill_params'] = array_merge($base_params, array(
                'action_type' => 'update',
            ));
        } elseif ($key === 'rolled_back') {
            $meta['drill_text'] = $rolled_back_text;
            $meta['drill_params'] = $base_params;
        } elseif ($key === 'skip_ratio') {
            $meta['drill_text'] = '查看跳过明细';
            $meta['drill_params'] = array_merge($base_params, array(
                'action_type' => 'skip',
            ));
        }

        return $meta;
    }

    /** 给单条风险原因（{key,points,text}）追加 drill_text/drill_params（来自 drillMeta()，回滚项用默认文案）并返回新数组——值传递不改入参，调用方（profile()）负责写回列表。batch 缺 id 时 drillMeta 给空骨架，链接自然消失。@param array $batch 批次行 @param array $reason 原因项 @return array 补了下钻元数据的数组 */
    public static function reasonMeta($batch, $reason): array
    {
        $batch_id = intval($batch['id'] ?? 0);
        $key = $reason['key'] ?? '';
        $drill = self::drillMeta($batch_id, $key);
        $reason['drill_text'] = $drill['drill_text'];
        $reason['drill_params'] = $drill['drill_params'];
        return $reason;
    }

    /** 给单个风险贡献项补展示色与下钻元数据并返回新数组：按 key 定色（skip_ratio 橙 #fd7e14 / rolled_back 红 #dc3545 / update_ratio 蓝 #0d6efd，其余灰 #6c757d），drill 文案对 rolled_back 固定为「查看批次详情」。颜色是内联样式常量（非用户输入），配合模板 {$var} 裸 echo 不引入注入面。@param array $batch 批次行（取 id） @param array $item 贡献项（含 key/label/points/ratio） @return array 补色 + 下钻元数据的数组 */
    public static function itemMeta($batch, $item): array
    {
        $batch_id = intval($batch['id'] ?? 0);
        $key = $item['key'] ?? '';
        $item['color'] = '#6c757d';
        if ($key === 'skip_ratio') {
            $item['color'] = '#fd7e14';
        } elseif ($key === 'rolled_back') {
            $item['color'] = '#dc3545';
        } elseif ($key === 'update_ratio') {
            $item['color'] = '#0d6efd';
        }
        $drill = self::drillMeta($batch_id, $key, '查看批次详情');
        $item['drill_text'] = $drill['drill_text'];
        $item['drill_params'] = $drill['drill_params'];
        return $item;
    }

    /** 风险分 → 等级键：>=70 'high'、>=35 'medium'、否则 'low'，与 riskLevelOptions() 的筛选值及列表 risk_level 内存过滤口径一致（label() 是同一阈值的中文形态）。@param int $score 0-100 风险分（无类型声明，实参来自 profile()） @return string */
    public static function levelKey($score): string
    {
        if ($score >= 70) return 'high';
        if ($score >= 35) return 'medium';
        return 'low';
    }

    /** 按风险主导项生成处置建议文案：dominant_key=skip_ratio 时再结合 skipBreakdown() 的主导细分（unbound/skipped_update/unchanged）给对应的下一步；update_ratio ⇒ 抽查更新范围；rolled_back ⇒ 复盘回滚原因；score<=0 且无主导项 ⇒ 一条「整体平稳」。返回前 array_unique + array_values 去重并重建下标。⚠ 会触发明细取数（skipBreakdown 最多扫 $fetch_limit 条），列表批量调用代价高，逐批次详情页才用。@param array $batch 批次行（读 id 与各计数列） @param int $fetch_limit 跳过明细扫描上限 @return array string[] 建议文案列表 */
    public static function suggestions($batch, $fetch_limit = 5000): array
    {
        $suggestions = array();
        $risk = self::profile($batch);
        $skip_breakdown = api_import_batch_skip_breakdown(intval($batch['id'] ?? 0), $fetch_limit);

        if (($risk['dominant_key'] ?? '') === 'skip_ratio') {
            if (($skip_breakdown['dominant_key'] ?? '') === 'unbound') {
                $suggestions[] = '优先检查分类绑定，当前主要是“未绑定分类”导致的跳过。';
            } elseif (($skip_breakdown['dominant_key'] ?? '') === 'skipped_update') {
                $suggestions[] = '优先复核更新范围与更新策略，当前主要是“跳过更新”在抬高风险。';
            } elseif (($skip_breakdown['dominant_key'] ?? '') === 'unchanged') {
                $suggestions[] = '当前跳过以“无变化”为主，可接受，但建议关注源站重复率是否持续偏高。';
            } else {
                $suggestions[] = '先下钻查看跳过明细，确认是无变化、跳过更新，还是未绑定分类导致。';
            }
        }
        if (($risk['dominant_key'] ?? '') === 'update_ratio') {
            $suggestions[] = '优先抽查更新明细和前后值，确认更新范围是否过宽、是否存在非预期覆盖。';
        }
        if (($risk['dominant_key'] ?? '') === 'rolled_back') {
            $suggestions[] = '该批次已回滚，建议复盘回滚原因后再重新导入，避免重复触发相同问题。';
        }
        if (empty($suggestions) && intval($risk['score'] ?? 0) <= 0) {
            $suggestions[] = '当前批次整体平稳，可继续按默认策略观察后续批次。';
        }

        return array_values(array_unique($suggestions));
    }
}
