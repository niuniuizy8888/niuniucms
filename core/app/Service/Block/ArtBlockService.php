<?php
namespace App\Service\Block;

class ArtBlockService
{
    /**
     * 单分类「最新文章」区块数据：ArtService::listByCid 两步取数（索引表→主表装配，desc=TRUE）。
     * 附带分类名/链接（cate_read，缓存缺失时各键走空串兜底）。
     * @param int $cid 分类 id
     * @param int $n 条数上限
     * @return array {artlist: array（无结果经 ?: 归一为空数组）, cate_name, cate_url, cid}
     */
    public static function single($cid, int $n): array
    {
        $cate = cate_read($cid);
        $artlist = \App\Service\Content\ArtService::listByCid($cid, 1, $n, true);
        return array(
            'artlist' => $artlist ?: array(),
            'cate_name' => $cate['name'] ?? '',
            'cate_url' => $cate['url'] ?? '',
            'cid' => $cate['cid'] ?? intval($cid),
        );
    }

    /**
     * 单分类「点击排行」区块数据：QueryService::rankArtByHit('hits', ...) 走累计点击榜
     * （topByCid 按主表 views 降序取 aid 回表装配，行补 n 名次；total 为 countByCid 口径）。
     * @param int $cid 分类 id
     * @param int $n 条数上限
     * @return array {artlist: array, cate_name, cate_url, cid}（结构同 single）
     */
    /**
     * 随机文章区块。⚠ $cid 只用于取展示用分类名，**不参与筛选**：
     * 候选取全站 art_aid 索引最新 max(n*5,100) 条，shuffle 后截 n 个，再 findAsc 回表。
     * 大站注意候选窗是「最新的 n*5 条内随机」，非全表随机。
     * @param int $cid 仅展示分类名
     * @param int $n 条数
     * @return array|NULL {list: 按 aid 升序的格式化行, cate_name}；索引/回表任一环为空 ⇒ NULL
     */
    public static function random($cid, int $n)
    {
        $rows = \App\Repository\RepositoryFactory::artAid()->find(array(), array('aid' => -1), 1, max($n * 5, 100), 'aid', array('aid'));
        if (!$rows) return NULL;
        $aids = array_keys($rows);
        $total = min(count($aids), $n);
        shuffle($aids);
        $aidarr = array_slice($aids, 0, $total);
        $items = \App\Service\Content\ArtService::findAsc($aidarr, $total);
        if (!$items) return NULL;
        $cate = cate_read($cid);
        return array(
            'list' => $items,
            'cate_name' => $cate['name'] ?? '',
        );
    }

    /**
     * 时间窗热榜区块：ArtService::hitRows（art_top 明细聚合，行形态 {aid,cnt}）取前 n 个 aid，
     * 再 findAsc 回表装配整行。⚠ 输出顺序是 aid 升序，hitRows 的 cnt 热度序在 array_column 处丢失。
     * @param string $type 'd'/'w'/'m' 或完整 hits_* 名
     * @param int $n 条数
     * @param int|array|null $cid 限定分类（NULL=全站）
     * @return array 文章行数组（热榜空或回表空 ⇒ 空数组）
     */
    public static function hitPeriod(string $type, int $n, $cid = NULL): array
    {
        $rows = \App\Service\Content\ArtService::hitRows($type, $n, $cid);
        if (empty($rows)) return array();
        $aids = array_column($rows, 'aid');
        return \App\Service\Content\ArtService::findAsc($aids, $n) ?: array();
    }
}
