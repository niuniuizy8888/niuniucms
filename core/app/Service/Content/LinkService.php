<?php
namespace App\Service\Content;

class LinkService
{
    /**
     * 新增/替换友情链接行（DbTableRepository::replace，REPLACE INTO 语义，依赖主键 id 判存）。
     * 副作用：deleteCache() 推进 link_cache_ver 版本令牌使列表缓存整体换代。
     * @param array $data link 表行数据
     * @return int|false REPLACE 的 lastInsertId（执行失败坍缩为 0）；$data 为空返回 FALSE
     */
    public static function create(array $data)
    {
        if (empty($data)) return FALSE;
        $r = \App\Repository\RepositoryFactory::link()->replace($data);
        self::deleteCache();
        return $r;
    }

    /**
     * 按 id 更新友情链接行；成功后推进 link_cache_ver 换代列表缓存。
     * @param int $id 主键（0 短路）
     * @param array $update 字段数组（空短路；列名 +/- 后缀=自增表达式）
     * @return int|false 影响行数；$id/$update 为空返回 FALSE
     */
    public static function update(int $id, array $update)
    {
        if (empty($id) || empty($update)) return FALSE;
        $r = \App\Repository\RepositoryFactory::link()->update(array('id' => $id), $update);
        self::deleteCache();
        return $r;
    }

    /**
     * 友链分页查询：无过滤、恒按 rank DESC。未传 $col ⇒ SELECT *（DbTableRepository 留痕 sql_star_select；link 列窄，属可接受兜底）。
     * @param int $page @param int $pagesize 默认 100（近全量一页）
     * @return array 行列表；无结果空 array
     */
    public static function find(int $page = 1, int $pagesize = 100)
    {
        return \App\Repository\RepositoryFactory::link()->find(array(), array('rank' => -1), $page, $pagesize);
    }

    /**
     * 前台友链列表缓存读取：键含 link_cache_ver 版本令牌 + page/pagesize（TTL 3600s），
     * 写路径经 deleteCache() 换代即时失效（旧键靠 TTL 自然过期，不逐键删除）。
     * ⚠ 空结果只记 30s（「开发约定·缓存」：空数组不得按正 TTL 缓存）——原来的 `3600` 会把
     *   「确实没有友链」与「查询异常被 prepare_execute 吞成空结果集」一起钉死一小时，
     *   表现为友链区块恒空白且无日志、无从区分。
     * @param int $page @param int $pagesize
     * @return array 友链行列表；空结果/异常恒归一为空 array（不返回 NULL）
     */
    public static function listCache(int $page = 1, int $pagesize = 100): array
    {
        $ver = (int)cache_get('link_cache_ver');
        $key = 'friends_link_' . $ver . '_' . $page . '_' . $pagesize;
        $rows = cache_get($key);
        if (NULL === $rows) {
            $rows = self::find($page, $pagesize);
            $rows = $rows ?: array();
            cache_set($key, $rows, empty($rows) ? 30 : 3600);
        }
        return $rows ?: array();
    }

    /**
     * 按 id 物理删除友链行；成功后推进 link_cache_ver。
     * @param int $id 主键（0 短路返回 FALSE）
     * @return int|false 影响行数（id 不存在为 0，不视为失败）
     */
    public static function delete(int $id)
    {
        if (empty($id)) return FALSE;
        $r = \App\Repository\RepositoryFactory::link()->delete(array('id' => $id));
        self::deleteCache();
        return $r;
    }

    /**
     * 拖拽排序批量落库：对每行 {id, rank, oldrank}，id 有效且 rank≠oldrank 才单条 update（循环逐行写，行数受后台拖拽规模约束）；
     * 有变更时推进一次 link_cache_ver。update 自身返回值未检查（个别行写失败不体现在计数上）。
     * @param array $rows 排序行数组（后台表单提交，字段经 param/intval 取用）
     * @return int 提交更新的行数（非「确认影响行数」）；入参空返回 0
     */
    public static function sortBatch(array $rows): int
    {
        if (empty($rows)) return 0;

        $changed = 0;
        foreach ($rows as $row) {
            $id = intval($row['id'] ?? 0);
            $rank = intval($row['rank'] ?? 0);
            $oldRank = intval($row['oldrank'] ?? 0);
            if (empty($id) || $rank === $oldRank) continue;
            \App\Repository\RepositoryFactory::link()->update(array('id' => $id), array('rank' => $rank));
            $changed++;
        }
        $changed > 0 and self::deleteCache();
        return $changed;
    }

    /**
     * 友链表条件计数（QueryBuilder count，签名恒 int，失败归 0）。
     * @param array $cond 条件数组 @param mixed $d 连接/从库选择器（NULL=默认）
     * @return int
     */
    public static function count(array $cond = array(), $d = NULL)
    {
        return \App\Repository\RepositoryFactory::link()->count($cond, $d);
    }

    /**
     * 缓存换代：link_cache_ver 自增（TTL 30 天）使 friends_link_* 各分页键整体失效——不清旧键，靠 3600s TTL 回收。
     * 版本读自 cache_get；缓存层整体故障时新写入按 (0+1) 起算，语义仍是「令牌变化」。
     * @return bool 恒 TRUE（cache_set 结果不回传）
     */
    public static function deleteCache(): bool
    {
        $ver = (int)cache_get('link_cache_ver');
        cache_set('link_cache_ver', $ver + 1, 86400 * 30);
        return TRUE;
    }
}
