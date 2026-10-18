<?php
namespace App\Service\Content;

/**
 * 回收站服务（软删除内容的暂存、还原与清理）
 * art/vod 删除时写入快照，支持列表/恢复/清空；恢复时重建主行与关联表
 */
class RecycleService
{
    const TYPE_ART = 'art';
    const TYPE_VOD = 'vod';

    /**
     * 回收站表仓库（recycle，主键 id）——本类读写唯一入口。
     * RepositoryFactory::table 对表名做白名单校验（违规返回空表名仓库，查询自然失败=fail-closed）。
     * @return \App\Repository\DbTableRepository
     */
    private static function repo()
    {
        return \App\Repository\RepositoryFactory::table('recycle', 'id');
    }

    /**
     * 只读回收记录的**类型**（art/vod，权限复核用，不取 data 大列）。
     * @param int $id 回收站记录主键（签名已声明；≤0 返回 null）
     * @return string|null 记录类型 'vod'/'art'；不存在或查询失败为 null
     */
    public static function readType(int $id)
    {
        if ($id <= 0) return null;
        $row = self::repo()->findOne(array('id' => $id), array(), array('id', 'type'));
        if (!$row) return null;
        $type = $row['type'] ?? '';
        return in_array($type, array(self::TYPE_ART, self::TYPE_VOD), true) ? $type : null;
    }

    /**
     * 写入回收站快照
     * @param string $type art/vod
     * @param int $targetId 原主键（aid/vid）
     * @param array $data 完整快照（art：['row'=>行,'aid'=>关联行数组]；vod：['row'=>行,'vod_vid'=>关联行数组]）
     */
    public static function put(string $type, int $targetId, array $data): bool
    {
        if (!in_array($type, array(self::TYPE_ART, self::TYPE_VOD), true) || $targetId <= 0) {
            return false;
        }
        $uid = intval(_SESSION('uid')) ?: 0;
        // ⚠ 序列化失败判据（复核修正）：行内若有无效 UTF-8，xn_json_encode 返回 false，旧实现直接写入
        //   `data=''`（列非空约束下也可能变成空串）**却返回成功** ⇒ 回收站里出现无法恢复的条目。
        //   现 fail-closed：编码失败即拒绝入站（调用方 snapshot* 会据此中止删除并留痕）。
        $json = xn_json_encode($data);
        if (false === $json || '' === (string)$json) {
            xn_log('recycle put: json encode failed for ' . $type . '#' . $targetId, 'recycle_error');
            return false;
        }
        $arr = array(
            'type' => $type,
            'target_id' => $targetId,
            'data' => $json,
            'uid' => $uid,
            'create_date' => intval(_SERVER('time')) ?: time(),
        );
        return (bool)self::repo()->insert($arr);
    }

    /**
     * 回收站列表（分页）
     * @param string $type 空=全部
     */
    public static function list(string $type = '', int $page = 1, int $pagesize = 20): array
    {
        $cond = array();
        if ('' !== $type) {
            $cond['type'] = $type;
        }
        // SQL 专项审计修复（R10）：列表排除 data(text)（回收站快照可达数十 KB/行）
        // ⚠ `find()` 第 5 参 $key 的语义：'' = 返回**顺序数组**（调用方按 $rows[0] 取值，
        // 是本方法的既有对外契约）；传列名 = 返回**以该列为键的关联数组**。
        // 故此处 $key 必须保持 ''，显式列只能走第 6 参——首版误把 'id' 放到第 5 参，
        // 使返回结构由顺序数组变为 id 键关联数组，调用方 $rows[0]['id'] 直接取空。
        return self::repo()->find($cond, array('id' => -1), $page, $pagesize, '', array('id', 'type', 'target_id', 'uid', 'create_date'));
    }

    /**
     * 按 id 批量取回收快照原文（列表页要显示标题，而标题只存在于快照 data 里）。
     * ⚠ 为什么不并进 list()：R10 明确让列表查询排除 data(text)——回收站快照可达数十 KB/行，
     *   整页一起拉会把「翻页浏览」变成大字段扫描。这里按**本页 id**（≤ pagesize 个）定点回取，
     *   既保住 R10 的收益，又补上 listPage() 此前直接读 $row['data']（列清单里根本没有该列）
     *   造成的 `Undefined array key "data"` + 标题恒为空串的缺陷。
     * @param array $ids recycle 表主键集合（非数值/≤0 自动丢弃）
     * @return array id => data 的 JSON 原文映射；库里没有的 id 不出现在结果中
     */
    public static function snapshotsByIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), function ($v) { return $v > 0; }));
        if (empty($ids)) return array();
        $rows = self::repo()->find(array('id' => $ids), array(), 1, count($ids), 'id', array('id', 'data'));
        $out = array();
        foreach ($rows as $row) {
            $out[intval($row['id'])] = (string)($row['data'] ?? '');
        }
        return $out;
    }

    /**
     * 回收站条目计数（QueryBuilder COUNT，带可选 type 过滤）。
     * @param string $type ''=全部类型；'art'/'vod'=按类型过滤
     * @return int 匹配行数（查询失败同为 0，无法区分空表/库错）
     */
    public static function count(string $type = ''): int
    {
        $cond = array();
        if ('' !== $type) {
            $cond['type'] = $type;
        }
        return self::repo()->count($cond);
    }

    /**
     * 恢复指定回收站条目
     * @return array ['code'=>1|-1,'msg'=>string]；1=成功，-1=失败（记录不存在/快照数据损坏/主键冲突），
     *               本方法不返回 0——调用方判成功请用 (int)$r['code'] === 1，勿写 !isset 或 code!=0 之类反向判断。
     */
    public static function restore(int $id): array
    {
        $row = self::repo()->read($id, 'id');
        if (!$row || empty($row['data'])) {
            return array('code' => -1, 'msg' => '回收站记录不存在');
        }
        $data = xn_json_decode($row['data']);
        if (!is_array($data) || empty($data['row'])) {
            return array('code' => -1, 'msg' => '快照数据损坏');
        }
        $ok = false;
        if (self::TYPE_ART === $row['type']) {
            $ok = self::restoreArt($data);
        } elseif (self::TYPE_VOD === $row['type']) {
            $ok = self::restoreVod($data);
        }
        if (!$ok) {
            return array('code' => -1, 'msg' => '恢复失败（主键可能已存在）');
        }
        // ⚠ 审查修复轮 C2：快照行清理失败要上报——内容已恢复成功，但回收站记录残留
        // （重复恢复会因主键已存在而安全失败，不会覆盖；留痕供后台感知清理异常）。
        // ⚠ 判据修正（复核）：DbTableRepository::delete 在**语句失败**时返回 FALSE、**0 行影响**时返回 0
        //   （QueryBuilder::delete 把 prepare 失败吞成 rowCount=0）⇒ 旧写法只判 false 是死分支，
        //   「清理失败」永远不会被留痕。现把 0 行一并视为未清理（并发 purge 也让管理员看到提示，无害）。
        $delRows = self::repo()->delete(array('id' => $id));
        if (false === $delRows || 0 === (int)$delRows) {
            xn_log('restore: recycle row not deleted for id=' . $id . ' (rows=' . var_export($delRows, true) . ')', 'recycle_error');
            return array('code' => 1, 'msg' => '已恢复（快照记录清理失败，请留意回收站残留）');
        }
        return array('code' => 1, 'msg' => '已恢复');
    }

    /**
     * 清空回收站（id=0 全清，否则单条物理删除）
     */
    public static function purge(int $id = 0): bool
    {
        if ($id > 0) {
            return (bool)self::repo()->delete(array('id' => $id));
        }
        return (bool)self::repo()->delete(array('id' => array('>' => 0)));
    }

    /**
     * 从快照重建 art 主行 + art_aid 关联，并重建标签绑定与计数、清 art_<aid> 与 runtime 计数缓存。
     * 防重复恢复：若目标 aid 在 art 主表已存在直接返回 false（不覆盖现有内容）。
     * ⚠ 无事务包裹——主行 insert 成功后，关联/标签/缓存清理若失败不回滚（部分恢复风险，见汇报）。
     * @param array $data 快照：['row'=>art 整行(含原 aid/tag JSON)]
     * @return bool 主行重建是否成功（aid 非法或已存在=FALSE）
     */
    private static function restoreArt(array $data): bool
    {
        $row = $data['row'];
        $aid = intval($row['aid'] ?? 0);
        if ($aid <= 0) return false;
        // 主键已存在则跳过（防重复恢复）
        if (\App\Repository\RepositoryFactory::art()->findOne(array('aid' => $aid))) {
            return false;
        }
        // 重建主行（保持原 aid）与 art_aid 关联
        // 重建主行（保持原 aid）与 art_aid 关联——insert($row) 直接按快照原 aid 落行
        $ok = (bool)\App\Repository\RepositoryFactory::art()->insert($row);
        if ($ok) {
            \App\Repository\RepositoryFactory::artAid()->replace(array('aid' => $aid, 'cid' => intval($row['cid'] ?? 0)));
            // 重建标签绑定 + 计数（rebindTags：只对仍存在、且绑定行已不存在的标签补插并 count+1）
            self::rebindTags($aid, (string)($row['tag'] ?? ''));
            cache_delete('art_' . $aid);
            cache_delete('runtime'); // 计数缓存失效（键错配修复）
        }
        return $ok;
    }

    /**
     * 恢复内容的标签绑定重建（restoreVod/restoreArt 共用）：
     * 删除链已把该内容的 tag_id 绑定行删掉，这里按快照 tag JSON（tagid=>name）重建，
     * 并只对「真正新增绑定」的标签 count+1。
     * ⚠ 历史缺陷（审计修复）：原实现只对 tag.count 批量 +1、不重建绑定行 ⇒ 恢复的内容在
     *   标签页/标签列表不可见（列表走 tag_id），且 count 相对真实绑定行数虚高，
     *   与删除链刚修掉的漂移方向相反。
     * 口径与 TagFlowService::process() 一致：
     *   ① 只处理仍存在的标签（内容待在回收站期间标签可能已被删除）；
     *   ② 先查本内容现存绑定（历史部分清理场景），已绑定的跳过、不重复计数；
     *   ③ 写入走 tag_video_big_insert（INSERT IGNORE，撞唯一键只跳过该行，兜并发/存量重复）；
     *   ④ 计数走 tag_update()（会失效 web_tag_{tagid} 缓存；原 bigUpdate 不清该缓存）。
     * @param int    $id      内容主键（vid/aid，写入 tag_id 的 vid 列）
     * @param string $tagJson 快照里的 tag JSON（tagid=>name）
     * @return void 失败不阻断主流程（内容已恢复，标签面按「少绑」收敛，缓存 TTL 后自愈）
     */
    private static function rebindTags(int $id, string $tagJson): void
    {
        $tagarr = xn_json_decode($tagJson);
        if (!is_array($tagarr) || !$tagarr) return;
        $tagids = array_values(array_filter(array_map('intval', array_keys($tagarr))));
        if (!$tagids) return;
        // 只处理仍存在的标签（待恢复期间可能已被删除）
        $existing = array();
        $rows = tag_find_by_tagids($tagids, 1, count($tagids));
        foreach ((array)$rows as $r) {
            $existing[intval($r['tagid'])] = true;
        }
        if (!$existing) return;
        $bound = array();
        foreach (\App\Service\Content\TagFlowService::videoFindByVidAll($id) as $r) {
            $bound[intval($r['tagid'])] = true;
        }
        $videoarr = array();
        $inc = array();
        foreach ($existing as $tid => $_) {
            if (isset($bound[$tid])) continue; // 绑定行仍在（历史部分清理）→ 不动，避免重复绑定与重复计数
            $videoarr[] = array('tagid' => $tid, 'vid' => $id);
            $inc[] = $tid;
        }
        !empty($videoarr) and tag_video_big_insert($videoarr);
        !empty($inc) and tag_update($inc, array('count+' => 1));
    }

    /**
     * 从快照重建 vod 主行 + vod_vid 关联，并重建标签绑定与计数、清 vod_<vid>/sticky_video_list/runtime 计数缓存。
     * 防重复恢复：若目标 vid 在 vod 主表已存在直接返回 false（不覆盖现有内容）。
     * ⚠ 无事务包裹——主行 insert 成功后，关联/标签/缓存清理若失败不回滚（与 restoreArt 同型部分恢复风险）。
     * @param array $data 快照：['row'=>vod 整行(含原 vid/tag JSON)]
     * @return bool 主行重建是否成功（vid 非法或已存在=FALSE）
     */
    private static function restoreVod(array $data): bool
    {
        $row = $data['row'];
        $vid = intval($row['vid'] ?? 0);
        if ($vid <= 0) return false;
        if (\App\Repository\RepositoryFactory::vod()->findOne(array('vid' => $vid))) {
            return false;
        }
        // 重建主行（保持原 vid）与 vod_vid 关联——insert($row) 直接按快照原 vid 落行
        $ok = (bool)\App\Repository\RepositoryFactory::vod()->insert($row); \App\Service\Front\TypeService::bumpFacets();
        if ($ok) {
            \App\Repository\RepositoryFactory::vodVid()->replace(array('vid' => $vid, 'cid' => intval($row['cid'] ?? 0)));
            // 重建标签绑定 + 计数（rebindTags：只对仍存在、且绑定行已不存在的标签补插并 count+1）
            self::rebindTags($vid, (string)($row['tag'] ?? ''));
            cache_delete('vod_' . $vid);
            cache_delete('sticky_video_list');
            cache_delete('runtime'); // 计数缓存失效（键错配修复）
        }
        return $ok;
    }
}
