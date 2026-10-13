<?php
namespace App\Service\Admin;

class VodManageService
{
    /**
     * 后台视频列表页数据组装（列表/筛选/分页 + 下发批量与海报两个场景的 safe_token 指纹）
     *
     * $filters 键：cid、page、pagesize、sticky（-1 不筛 / 0 仅未推荐 / 1 仅推荐）、range（today|7d|30d 按 create_date 圈定）。
     * 无过滤或仅按 cid 时走索引快速路径（countByCid/indexIds，首页且无 sticky/range 筛选时置顶行前置合并）；
     * 混挂其他条件时退化为 count + findPageByCond 常规条件查询。
     * cid 指定但分类不存在时 message(1, cate_not_exists) 直接终止。
     *
     * @param array $filters 已 htmlspecialchars 化的请求参数（param() 出口，勿再转义）
     * @param int $uid 当前管理员 uid（bull_token_set 场景绑定）
     * @return array 视图数据：videolist 可为 NULL（无结果）；pagination 为 HTML 串；
     *               safe_token=admin_vod_batch、poster_token=admin_vod_poster 场景指纹
     */
    public static function listPage(array $filters, int $uid): array
    {
        $catelist = cate_list_cache();
        $cid = intval($filters['cid'] ?? 0);
        $page = intval($filters['page'] ?? 1);
        $pagesize = intval($filters['pagesize'] ?? 20);
        $sticky = intval($filters['sticky'] ?? -1);
        $range = (string)($filters['range'] ?? '');

        $extra = array('page' => '{page}', 'cid' => $cid, 'pagesize' => $pagesize, 'sticky' => $sticky, 'range' => $range);
        $cond = array();
        $cate = array();
        if ($cid) {
            $cate = array_value($catelist, $cid);
            empty($cate) and message(1, lang('cate_not_exists'));
            $cond['cid'] = $cid;
        }
        if ($sticky == 1) {
            $cond['sticky'] = array('>' => 0);
        } elseif ($sticky == 0) {
            $cond['sticky'] = 0;
        }
        if ($range) {
            $now = time();
            $start = 0;
            if ('today' == $range) {
                $start = strtotime(date('Y-m-d 00:00:00', $now));
            } elseif ('7d' == $range) {
                $start = strtotime('-7 day', $now);
            } elseif ('30d' == $range) {
                $start = strtotime('-30 day', $now);
            }
            $start and $cond['create_date'] = array('>=' => $start, '<' => $now + 1);
        }

        $use_index = empty($cond) || (1 == count($cond) && isset($cond['cid']));
        if ($use_index) {
            if ($cid) {
                $n = \App\Service\Content\VideoService::countByCid($cid);
                $vidlist = $n ? \App\Service\Content\VideoService::listIndexByCid($cid, $page, $pagesize) : NULL;
            } else {
                $n = \App\Service\Content\VideoService::indexCount();
                $vidlist = $n ? \App\Service\Content\VideoService::indexIds($page, $pagesize, TRUE) : NULL;
            }
            if (1 == $page && $sticky < 0 && !$range) {
                $stickylist = $cid ? \App\Service\Content\VideoService::stickyListMap($cid) : \App\Service\Content\VideoService::stickyIndexMap();
                $vidlist = (array)$stickylist + (array)$vidlist;
            }
            if (empty($vidlist)) {
                $videolist = NULL;
            } else {
                $vidarr = arrlist_values($vidlist, 'vid');
                $videolist = \App\Service\Content\VideoService::find($vidarr, count($vidlist), TRUE, TRUE);
                $videolist = array2_sort_key($videolist, $vidlist, 'vid');
            }
        } else {
            $n = \App\Service\Content\VideoService::count($cond);
            $videolist = $n ? \App\Service\Content\VideoService::findPageByCond($cond, $page, $pagesize, array('vid' => -1), TRUE) : NULL;
        }

        return array(
            'header' => array(
                'title' => lang('vod_page'),
            ),
            'cid' => $cid,
            'cate' => $cate,
            'videolist' => $videolist,
            'columnlist' => category_list($catelist),
            'filter' => array('sticky' => $sticky, 'pagesize' => $pagesize, 'range' => $range),
            'pagination' => pagination(url('vod-list', $extra, TRUE), $n, $page, $pagesize),
            'safe_token' => bull_token_set($uid, 'admin_vod_batch'),
            'poster_token' => bull_token_set($uid, 'admin_vod_poster'), // 海报更新按钮（此前从未下发致功能恒失败）
        );
    }

    /**
     * 后台视频搜索页数据组装：关键词为纯数字时按 vid 精确读单条（VideoService::read），
     * 否则走 findByKeyword（前导通配 LIKE，上限 60 条，全表扫风险见 CLAUDE.md「开发约定·N+1 红线」/P-4）。
     * 空关键词返回 videolist=NULL、pagination 空串。
     * @param string $keyword 搜索词（调用方经 param() 取得，已转义）
     * @param int $uid 当前管理员 uid
     * @return array 结构同 listPage（filter 为默认值：sticky=-1/pagesize=20/range=''）
     */
    public static function searchPage(string $keyword, int $uid): array
    {
        $catelist = cate_list_cache();
        $videolist = NULL;
        if ($keyword !== '') {
            if (ctype_digit($keyword)) {
                $video = \App\Service\Content\VideoService::read(intval($keyword));
                $videolist = $video ? array($video['vid'] => $video) : NULL;
            } else {
                $videolist = \App\Service\Content\VideoService::findByKeyword($keyword);
            }
        }
        return array(
            'header' => array(
                'title' => lang('vod_page'),
            ),
            'cid' => 0,
            'videolist' => $videolist,
            'columnlist' => category_list($catelist),
            'filter' => array('sticky' => -1, 'pagesize' => 20, 'range' => ''),
            'pagination' => '',
            'safe_token' => bull_token_set($uid, 'admin_vod_batch'),
            'poster_token' => bull_token_set($uid, 'admin_vod_poster'), // 海报更新按钮（此前从未下发致功能恒失败）
        );
    }

    /**
     * 删除动作入口：先 verifyBatchToken（admin_vod_batch 场景，fail-closed），
     * 取 admin_param_ids('vid')（去重过滤 0、上限 500 截断，VULN-M09）；单 id 解包为标量后交
     * VideoService::deleteAll（软删回收站 + 级联清理索引/标签/置顶）。deleteAll 返 FALSE（空/全不存在/写失败）时 message(-1) 终止。
     * 成功写 admin_audit_log('vod_delete')。
     * @param int $uid 当前管理员 uid
     * @return array {code:0, message}（失败路径经 message 终止，不返回）
     */
    public static function deleteAction(int $uid): array
    {
        self::verifyBatchToken($uid);
        $vids = admin_param_ids('vid');
        empty($vids) and message(-1, lang('data_malformation'));
        $deleteTarget = count($vids) == 1 ? $vids[0] : $vids;
        FALSE === self::delete($deleteTarget) and message(-1, lang('delete_failed'));
        admin_audit_log('vod_delete', array('count' => count($vids), 'ids' => implode(',', $vids)));
        return array('code' => 0, 'message' => lang('delete_successfully'));
    }

    /**
     * 批量操作入口：verifyBatchToken + admin_param_ids('ids')（≤500）后按 batch_action 分发：
     * delete（同 deleteAction 语义）、sticky_on（0 条生效即 message -1「数据不存在」）、
     * sticky_off（恒回成功，done 为请求条数，见 stickyBatch）；未知动作 message(-1)。
     * @param int $uid 当前管理员 uid
     * @return array {code:0, message}（失败路径 message 终止）
     */
    public static function batchAction(int $uid): array
    {
        self::verifyBatchToken($uid);
        $batchAction = admin_param_text('batch_action', '', 32);
        $ids = admin_param_ids('ids');
        empty($ids) and message(-1, '请选择要操作的视频');

        switch ($batchAction) {
            case 'delete':
                $deleteTarget = count($ids) == 1 ? $ids[0] : $ids;
                FALSE === self::delete($deleteTarget) and message(-1, lang('delete_failed'));
                admin_audit_log('vod_batch_delete', array('count' => count($ids), 'ids' => implode(',', $ids)));
                return array('code' => 0, 'message' => '批量删除成功');
            case 'sticky_on':
                $done = self::stickyBatch($ids, TRUE);
                empty($done) and message(-1, '数据不存在');
                admin_audit_log('vod_batch_sticky_on', array('count' => $done, 'ids' => implode(',', $ids)));
                return array('code' => 0, 'message' => "已设置推荐 {$done} 条");
            case 'sticky_off':
                $done = self::stickyBatch($ids, FALSE);
                admin_audit_log('vod_batch_sticky_off', array('count' => $done, 'ids' => implode(',', $ids)));
                return array('code' => 0, 'message' => "已取消推荐 {$done} 条");
            default:
                message(-1, '不支持的批量操作');
        }
    }

    /**
     * 单条视频推荐开关入口：verifyBatchToken 后取 vid（min 1）与 sticky（0|1）。
     * sticky=0：stickySet($vid,0) 后一律回「取消推荐成功」（视频不存在时不报错，见 stickyDelete 的 FALSE 语义）；
     * sticky=1：stickySet 失败（视频不存在/写失败）message(-1, video_not_exists)。
     * 成功分支均写 admin_audit_log（off=vod_sticky_off，on=vod_sticky）。
     * @param int $uid 当前管理员 uid
     * @return array {code:0, message: jump() 生成的跳转 HTML}
     */
    public static function stickyAction(int $uid): array
    {
        self::verifyBatchToken($uid);
        $vid = admin_param_int('vid', 0, 1);
        $sticky = admin_param_int('sticky', 0, 0, 1);

        switch ($sticky) {
            case 0:
                self::stickySet($vid, 0);
                admin_audit_log('vod_sticky_off', array('vid' => $vid));
                return array('code' => 0, 'message' => jump('取消推荐成功', url('vod-list', array(), TRUE), 3));
            case 1:
                if (self::stickySet($vid, 1)) {
                    admin_audit_log('vod_sticky', array('vid' => $vid, 'action' => 'pin'));
                    return array('code' => 0, 'message' => jump('设置推荐成功', url('vod-list', array(), TRUE), 3));
                }
                message(-1, lang('video_not_exists'));
            default:
                message(-1, lang('data_malformation'));
        }
    }

    /**
     * 删除薄封装：VideoService::deleteAll（软删快照入回收站 + 清理 vod_vid/tag_id/sticky 关联）。
     * @param int|array $vids 单 vid 或 vid 数组（建议经 admin_param_ids 取得，≤500）
     * @return bool 仅当 deleteAll 返回 FALSE（入参空、vids 全部不存在或主表删除失败）时为 false
     */
    public static function delete($vids): bool
    {
        return FALSE !== \App\Service\Content\VideoService::deleteAll($vids);
    }

    /**
     * 批量推荐/取消推荐。
     * $on=TRUE：先 find($ids) 取存在行，逐条 VideoService::stickyChange（循环内每行 2 次写：vod.update + vod_sticky.replace），返回实际生效条数（ids 全部不存在返回 0）。
     * $on=FALSE：逐条 stickyDelete，不校验视频是否存在，计数恒等于 count($ids)（stickyDelete 失败不感知——返回值即「请求数」而非「实际删除数」）。
     * @param array $ids vid 数组（admin_param_ids 出口，≤500）
     * @param bool $on TRUE=置推荐，FALSE=取消推荐
     * @return int 处理条数（语义见上，两侧不对称）
     */
    public static function stickyBatch(array $ids, bool $on): int
    {
        if ($on) {
            $videolist = \App\Service\Content\VideoService::find($ids, count($ids));
            if (empty($videolist)) return 0;
            $done = 0;
            foreach ($videolist as $_video) {
                \App\Service\Content\VideoService::stickyChange($_video['vid'], 1, $_video);
                $done++;
            }
            return $done;
        }

        $done = 0;
        foreach ($ids as $_vid) {
            \App\Service\Content\VideoService::stickyDelete($_vid);
            $done++;
        }
        return $done;
    }

    /**
     * 重复视频检测页数据：VideoService::findDuplicates（按 name 分组 COUNT>1，GROUP_CONCAT vids；无 DB 连接返回空 array）+ admin_vod_duplicate 场景 safe_token。
     * @param int $uid 当前管理员 uid
     * @return array {header, duplicate_list, safe_token}
     */
    public static function duplicatePage(int $uid): array
    {
        return array(
            'header' => array(
                'title' => lang('vod_page'),
            ),
            'duplicate_list' => \App\Service\Content\VideoService::findDuplicates(),
            'safe_token' => bull_token_set($uid, 'admin_vod_duplicate'),
        );
    }

    /**
     * 重复视频处置入口：先 admin_bull_token_guard（admin_vod_duplicate 场景，fail-closed，校验失败分类 message 终止）。
     * batch_merge_max/min：groups 以 param($k,'',FALSE) 取原始值（绕 htmlspecialchars，供 urldecode+json_decode），解码失败或空即 message(-1)，成功写 admin_audit_log('vod_duplicate_'.action)。
     * delete_max/min：vids 逗号串 explode+intval 后交 duplicateDeleteExtreme，返回 0/FALSE 时 message(-1,'删除失败')。
     * @param int $uid 当前管理员 uid
     * @return array {code:0, message}（未知 action 走 message(-1) 终止）
     */
    public static function duplicateAction(int $uid): array
    {
        $safeToken = param('safe_token');
        admin_bull_token_guard($uid, 'admin_vod_duplicate', $safeToken);
        $action = param('action');

        switch ($action) {
            case 'batch_merge_max':
            case 'batch_merge_min':
                $groups = param('groups', '', FALSE);
                empty($groups) and message(-1, '参数错误: groups为空');
                $groups = urldecode($groups);
                $groups = json_decode($groups, true);
                !is_array($groups) and message(-1, '参数格式错误: ' . json_last_error_msg() . ' - ' . $groups);
                $successCount = self::duplicateBatchMerge($action, $groups);
                admin_audit_log('vod_duplicate_' . $action, array('count' => $successCount));
                return array('code' => 0, 'message' => "成功合并 $successCount 组视频");
            case 'delete_max':
            case 'delete_min':
                $groupVid = param('group_vid');
                $vids = param('vids');
                if (empty($groupVid) || empty($vids)) {
                    message(-1, '参数错误');
                }
                $vids = array_map('intval', explode(',', $vids));
                $deletedVid = self::duplicateDeleteExtreme($vids, 'delete_max' == $action);
                if ($deletedVid) {
                    admin_audit_log('vod_duplicate_' . $action, array('vid' => intval($deletedVid)));
                    return array('code' => 0, 'message' => 'delete_max' == $action ? '删除最大ID成功' : '删除最小ID成功');
                }
                message(-1, '删除失败');
            default:
                message(-1, '未知操作');
        }
    }

    /**
     * 按组批量合并重复视频：每 group['vids'] 为 "vid,vid,…" 逗号串（intval 后去 0 依赖下游）；
     * batch_merge_max 以最大 vid 为合并目标、batch_merge_min 以最小 vid 为目标，其余作为源交
     * VideoService::mergeVideos（聚合 play_* 四列后写回目标、成功才 deleteAll 源视频；目标不存在或源无播放地址时该组失败）。
     * 组内无 vids 键、mergeVideos 返回假值均计入失败但不中断。
     * @param string $action batch_merge_max|batch_merge_min
     * @param array $groups 组数组，每项含 vids 逗号串（调用方已 json_decode/校验为数组）
     * @return int 合并成功的组数
     */
    public static function duplicateBatchMerge(string $action, array $groups): int
    {
        $success_count = 0;
        foreach ($groups as $group) {
            if (!isset($group['vids'])) continue;
            $vids = array_map('intval', explode(',', $group['vids']));
            $target_vid = ('batch_merge_max' == $action) ? max($vids) : min($vids);
            $source_vids = array_values(array_filter($vids, function ($vid) use ($target_vid) {
                return $vid != $target_vid;
            }));
            if (\App\Service\Content\VideoService::mergeVideos($target_vid, $source_vids)) {
                $success_count++;
            }
        }
        return $success_count;
    }

    /**
     * 单条推荐开关：$sticky=0 走 stickyDelete（幂等；视频不存在时返回 FALSE）；
     * $sticky>0 先 read 校验视频存在再 stickyChange 写 vod.sticky + REPLACE vod_sticky。
     * 注意：本路径不刷新 sticky_video_list/sticky_video_ver 缓存（见 VideoStickyTrait::stickyChange），
     * 前台置顶区最长滞后缓存 TTL（1800s）。
     * @param int $vid 视频 ID
     * @param int $sticky 0=取消推荐，非 0=置推荐（当前入口只传 1）
     * @return bool 底层写返回 FALSE（视频不存在/写失败）时为 false
     */
    public static function stickySet(int $vid, int $sticky): bool
    {
        if (0 === $sticky) {
            return FALSE !== \App\Service\Content\VideoService::stickyDelete($vid);
        }
        $video = \App\Service\Content\VideoService::read($vid);
        if (!$video) return FALSE;
        return FALSE !== \App\Service\Content\VideoService::stickyChange($vid, 1, $video);
    }

    /**
     * 删除重复组内极值视频：intval 过滤 0 后取 max（$deleteMax=TRUE）或 min 单个 vid，走 VideoService::delete（软删快照）。
     * @param array $vids 组内 vid 列表（int 或数字串）
     * @param bool $deleteMax TRUE=删 ID 最大，FALSE=删 ID 最小
     * @return int|false 被删 vid；入参全空或单删失败返回 FALSE（vids 空数组先短路，不触库）
     */
    public static function duplicateDeleteExtreme(array $vids, bool $deleteMax)
    {
        $vids = array_values(array_filter(array_map('intval', $vids)));
        if (empty($vids)) return FALSE;
        $target = $deleteMax ? max($vids) : min($vids);
        if (\App\Service\Content\VideoService::delete($target)) {
            return $target;
        }
        return FALSE;
    }

    /**
     * 统一校验 admin_vod_batch 场景的 safe_token 会话指纹环（admin_bull_token_guard：fail-closed，
     * 缺失/过期/跨会话/会话为空按类别 message 终止并写对应 admin_audit 分类日志；通过则静默返回）。
     * @param int $uid 当前管理员 uid
     * @return void
     */
    private static function verifyBatchToken(int $uid): void
    {
        $safeToken = param('safe_token');
        admin_bull_token_guard($uid, 'admin_vod_batch', $safeToken);
    }
}
