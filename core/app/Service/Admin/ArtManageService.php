<?php
namespace App\Service\Admin;

class ArtManageService
{
    /**
     * 后台文章列表页装配（视图数据数组，非渲染）。
     * filters：cid（栏目不存在直接 message(1) 终止）/page/pagesize/range（today|7d|30d 创建时间窗，
     * 其他值视为不限）。取数双路：无过滤或仅 cid ⇒ art_aid 索引表分页 + 批量装配并按索引顺序重排；
     * 带时间窗 ⇒ art 主表条件 count + findPageByCond（条件查询无索引红利时的兜底路径）。
     * 返回里的 safe_token 是 bull_token_set($uid, 'admin_art_batch') 的会话场景指纹环签发，
     * 供本页批量删除表单过 admin_bull_token_guard（fail-closed）。
     * @param array $filters 路由侧已取好的过滤参数
     * @param int $uid 当前管理员 uid
     * @return array header/cid/cate/artlist/columnlist/filter/pagination/safe_token（artlist 可为 NULL）
     */
    public static function listPage(array $filters, int $uid): array
    {
        $catelist = cate_list_cache();
        $catelists = arrlist_cond_orderby($catelist, array('model' => 1), array(), 1, 200);
        $cid = intval($filters['cid'] ?? 0);
        $page = intval($filters['page'] ?? 1);
        $pagesize = intval($filters['pagesize'] ?? 20);
        $range = (string)($filters['range'] ?? '');

        $extra = array('page' => '{page}', 'cid' => $cid, 'pagesize' => $pagesize, 'range' => $range);
        $cond = array();
        $cate = array();
        if ($cid) {
            $cate = array_value($catelist, $cid);
            empty($cate) and message(1, lang('cate_not_exists'));
            $cond['cid'] = $cid;
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
                $n = \App\Service\Content\ArtService::indexCount(array('cid' => $cid));
                $aidlist = $n ? \App\Service\Content\ArtService::listIndexByCid($cid, $page, $pagesize) : NULL;
            } else {
                $n = \App\Service\Content\ArtService::indexCount();
                $aidlist = $n ? \App\Service\Content\ArtService::indexIds($page, $pagesize, TRUE, 'aid', array('aid')) : NULL;
            }
            if (empty($aidlist)) {
                $artlist = NULL;
            } else {
                $aidarr = arrlist_values($aidlist, 'aid');
                $artlist = \App\Service\Content\ArtService::find($aidarr, count($aidlist));
                $artlist = array2_sort_key($artlist, $aidlist, 'aid');
            }
        } else {
            $n = \App\Service\Content\ArtService::count($cond);
            $artlist = $n ? \App\Service\Content\ArtService::findPageByCond($cond, $page, $pagesize) : NULL;
        }

        return array(
            'header' => array(
                'title' => lang('art_page'),
            ),
            'cid' => $cid,
            'cate' => $cate,
            'artlist' => $artlist,
            'columnlist' => category_list($catelists),
            'filter' => array('pagesize' => $pagesize, 'range' => $range),
            'pagination' => pagination(url('art-list', $extra, TRUE), $n, $page, $pagesize),
            'safe_token' => bull_token_set($uid, 'admin_art_batch'),
        );
    }

    /**
     * 后台文章搜索页装配：纯数字 keyword 按 aid 直读单条（read()），否则
     * ArtService::findByKeyword——底层是前导通配 LIKE '%kw%'（必然全表扫，CLAUDE.md「开发约定·N+1 红线」已裁定；
     * vod 才有 ngram FULLTEXT opt-in 通道，art 无）。keyword 为空 ⇒ 不查询，artlist=NULL。
     * 返回结构与 listPage 同款但 pagination 恒为 ''（搜索结果不分页）。
     * @param string $keyword 已清洗的关键词
     * @param int $uid 当前管理员 uid（签发 admin_art_batch 场景 safe_token）
     * @return array 视图数据数组
     */
    public static function searchPage(string $keyword, int $uid): array
    {
        $catelist = cate_list_cache();
        $catelists = arrlist_cond_orderby($catelist, array('model' => 1), array(), 1, 200);
        $artlist = NULL;
        if ($keyword !== '') {
            if (ctype_digit($keyword)) {
                $art = \App\Service\Content\ArtService::read(intval($keyword));
                $artlist = $art ? array($art['aid'] => $art) : NULL;
            } else {
                $artlist = \App\Service\Content\ArtService::findByKeyword($keyword);
            }
        }
        return array(
            'header' => array(
                'title' => lang('art_page'),
            ),
            'cid' => 0,
            'artlist' => $artlist,
            'columnlist' => category_list($catelists),
            'filter' => array('pagesize' => 20, 'range' => ''),
            'pagination' => '',
            'safe_token' => bull_token_set($uid, 'admin_art_batch'),
        );
    }

    /**
     * 后台删除文章动作（POST 入口调用）：先 verifyBatchToken()（CSRF+会话指纹环，fail-closed，
     * 不过即 message 终止）→ admin_param_ids('aid') 取 id（去重取整、上限 500，VULN-M09）→
     * 空即 message(-1) → 单 id 传标量、多 id 传数组进 delete() → FALSE 即 message 删除失败 →
     * 成功写 admin_audit_log('art_delete')。
     * @param int $uid 当前管理员 uid（token 校验主体）
     * @return array ['code' => 0, 'message' => 删除成功]——失败路径都经 message() 终止，不会走到返回
     */
    public static function deleteAction(int $uid): array
    {
        self::verifyBatchToken($uid);
        $aids = admin_param_ids('aid');
        empty($aids) and message(-1, lang('data_malformation'));
        $deleteTarget = count($aids) == 1 ? $aids[0] : $aids;
        FALSE === self::delete($deleteTarget) and message(-1, lang('delete_failed'));
        admin_audit_log('art_delete', array('count' => count($aids), 'ids' => implode(',', $aids)));
        return array('code' => 0, 'message' => lang('delete_successfully'));
    }

    /**
     * 后台批量操作分发（POST）：同样先 verifyBatchToken()，batch_action 走 admin_param_text 取参，
     * ids 走 admin_param_ids（500 上限）。动作白名单目前仅 'delete'（与 deleteAction 同口径：
     * 单 id 标量/多 id 数组、审计通道 art_batch_delete）；其他动作 message(-1) 拒绝——
     * 新增批量动作必须显式加 case，不给兜底放行。
     * @param int $uid 当前管理员 uid
     * @return array 仅 delete 成功时返回 ['code' => 0, ...]；其余分支经 message() 终止
     */
    public static function batchAction(int $uid): array
    {
        self::verifyBatchToken($uid);
        $batchAction = admin_param_text('batch_action', '', 32);
        $ids = admin_param_ids('ids');
        empty($ids) and message(-1, '请选择要操作的文章');

        switch ($batchAction) {
            case 'delete':
                $deleteTarget = count($ids) == 1 ? $ids[0] : $ids;
                FALSE === self::delete($deleteTarget) and message(-1, lang('delete_failed'));
                admin_audit_log('art_batch_delete', array('count' => count($ids), 'ids' => implode(',', $ids)));
                return array('code' => 0, 'message' => '批量删除成功');
            default:
                message(-1, '不支持的批量操作');
        }
    }

    /**
     * 删除文章（单 id 或数组）：转发 ArtService::deleteAll()（回收站快照 + 主表/索引表删除 +
     * 标签关联与计数清理，链路内无权限判定——门禁由调用方路由负责）。
     * ⚠ deleteAll 返回值不反映各步 rowCount：TRUE 只代表链路走完；入参空或库中无对应行是 FALSE。
     * @param int|array $aids aid 或 aid 数组
     * @return bool FALSE !== ArtService::deleteAll($aids)
     */
    public static function delete($aids): bool
    {
        return FALSE !== \App\Service\Content\ArtService::deleteAll($aids);
    }

    /**
     * 批量写操作统一 CSRF/会话门禁：param('safe_token')（默认已 htmlspecialchars，令牌口径与签发侧
     * 一致）→ admin_bull_token_guard($uid, 'admin_art_batch', $token)。
     * fail-closed：校验不过由 guard 内部按失败类别（stale/session_expired/session_empty/other_session/
     * illegal）写审计并 message 终止，本函数无返回值、不会「校验失败继续执行」。
     * @param int $uid 当前管理员 uid
     * @return void
     */
    private static function verifyBatchToken(int $uid): void
    {
        $safeToken = param('safe_token');
        admin_bull_token_guard($uid, 'admin_art_batch', $safeToken);
    }
}
