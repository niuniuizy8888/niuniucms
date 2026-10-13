<?php
namespace App\Service\Block;

class VodBlockService
{
    /**
     * 全站/分类按累计 views 降序的热门区块（模板 block 装配入口）。
     * SQL 审计 R10 口径：显式轻量列（findColsLight，排除 play_url 等大字段）+ format(light) 不产 play_data。
     * @param mixed $cid 分类过滤（空=全站）；非空作为 find 的 cid 条件
     * @param int $n 取前 N 条
     * @return array vid 键的视频轻量行数组（每行补 n 序号）；无数据返回空数组
     */
    public static function top($cid, int $n)
    {
        $cond = empty($cid) ? array() : array('cid' => $cid);
        // SQL 专项审计修复（R10 / P0-2）：原省略 col ⇒ SELECT * 拉全 40 列，
        // 含 vod.play_url（实测均值 5,398 字节 / 最大 171,527 字节）。本方法随后以
        // format(..., true) 轻量装配（不产 play_data）⇒ 大字段纯浪费。改为显式列。
        $rows = \App\Repository\RepositoryFactory::vod()->find(
            $cond,
            array('views' => -1),
            1,
            $n,
            'vid',
            \App\Service\Content\VideoService::findColsLight()
        );
        if ($rows) {
            $i = 0;
            foreach ($rows as &$video) {
                ++$i;
                \App\Service\Content\VideoService::format($video, true);
                $video['n'] = $i;
            }
            unset($video);
        }
        return $rows ?: array();
    }

    /**
     * 单分类最新视频区块（block 语法 vid 列表形态）：按 vid 降序取该分类前 $n 条 + 分类名/链接。
     * @param mixed $cid 分类 ID（空分类时 listByCid 与 cate_read 均返回空，videolist 坍缩为空数组）
     * @param int $n 条数
     * @return array ['videolist'=>vid 键轻量行数组, 'cate_name'=>string, 'cate_url'=>string, 'cid'=>int]
     */
    public static function single($cid, int $n): array
    {
        $cate = cate_read($cid);
        $videolist = \App\Service\Content\VideoService::listByCid($cid, 1, $n, true, true);
        return array(
            'videolist' => $videolist ?: array(),
            'cate_name' => $cate['name'] ?? '',
            'cate_url' => $cate['url'] ?? '',
            'cid' => $cate['cid'] ?? intval($cid),
        );
    }

    /**
     * 多分类独立列表批量预取（N+1 → 1 次 vid 索引查询 + 1 次视频批量拉取）
     * 与 single() 返回结构一致：array(cid => array('videolist','cate_name','cate_url','cid'))
     */
    public static function singleBatch(array $cids, int $n): array
    {
        $res = array();
        if (empty($cids) || $n <= 0) return $res;
        $n = min(intval($n), 100); // 上限保护：block 参数可控时防过大窗口
        $cids = array_slice(array_values(array_map('intval', $cids)), 0, 50); // cids 数量上限：防窗口 n*count 放大
        // 窗口 3 倍余量：全局排序下冷门分类可能被热门占满窗口，不足部分按 cid 补查保证每组 n 条
        $vidrows = video_vid__find(array('cid' => array_values($cids)), array('vid' => -1), 1, $n * count($cids) * 3, 'vid', array('vid', 'cid'));
        $grouped = array();
        foreach ($vidrows as $row) {
            $cid = intval($row['cid']);
            if (!isset($grouped[$cid])) $grouped[$cid] = array();
            if (count($grouped[$cid]) >= $n) continue;
            $grouped[$cid][] = intval($row['vid']);
        }
        $all = array();
        foreach ($grouped as $vids) {
            foreach ($vids as $vid) $all[] = array('vid' => $vid);
        }
        $pulled = \App\Service\Content\VideoService::unifiedPull(array('vidlist' => $all, 'light' => TRUE));
        $videolist = isset($pulled['videolist']) ? $pulled['videolist'] : array();
        foreach ($cids as $cid) {
            $cate = cate_read($cid);
            $items = array();
            foreach (isset($grouped[$cid]) ? $grouped[$cid] : array() as $vid) {
                if (isset($videolist[$vid])) $items[$vid] = $videolist[$vid];
            }
            // 窗口不足时按 cid 补查，严格保证每组 n 条（与 single() 语义一致）
            if (count($items) < $n) {
                $extra = \App\Service\Content\VideoService::listByCid($cid, 1, $n, true, true);
                foreach ($extra as $vid => $video) {
                    if (!isset($items[$vid])) $items[$vid] = $video;
                }
            }
            $res[$cid] = array(
                'videolist' => $items,
                'cate_name' => $cate['name'] ?? '',
                'cate_url' => $cate['url'] ?? '',
                'cid' => $cate['cid'] ?? intval($cid),
            );
        }
        return $res;
    }

    /**
     * 单分类热度排行区块（结构同 single，但 videolist 来自累计 hits 榜而非最新）。
     * @param mixed $cid 分类 ID
     * @param int $n 取前 N 条
     * @return array ['videolist'=>normalizeRankItems 规整后的条目数组, 'cate_name'=>string, 'cate_url'=>string, 'cid'=>int]
     */
    public static function singleTop($cid, int $n): array
    {
        $cate = cate_read($cid);
        $ranked = \App\Service\Content\QueryService::rankVideoByHit('hits', $cid, 1, $n);
        return array(
            'videolist' => self::normalizeRankItems($ranked['items'] ?? array()),
            'cate_name' => $cate['name'] ?? '',
            'cate_url' => $cate['url'] ?? '',
            'cid' => $cate['cid'] ?? intval($cid),
        );
    }

    /**
     * 频道页数据装配：本频道（含子分类并集）视频列表 + hits 榜 + 频道节点 + 计数。
     * @param int $cid 频道根分类 ID
     * @param array $catelist 全部分类表（用于求子分类并集）
     * @param array $cate_nav 导航分类映射（取当前频道节点）
     * @param int $pagesize 列表页大小
     * @return array ['videolist'=>条目数组, 'top'=>normalizeRankItems 规整榜单, 'cate'=>频道节点(缺失为空数组), 'videos'=>int 总数]
     */
    public static function channel($cid, array $catelist, array $cate_nav, int $pagesize): array
    {
        $query = \App\Service\Content\QueryService::channelVideoList($cid, $catelist, 1, $pagesize);
        $ranked = \App\Service\Content\QueryService::rankChannelVideoByHit('hits', $query['child_cids'], 1, $pagesize);
        return array(
            'videolist' => $query['items'] ?: array(),
            'top' => self::normalizeRankItems($ranked['items'] ?? array()),
            'cate' => $cate_nav[$cid] ?? array(),
            'videos' => intval($query['total'] ?? 0),
        );
    }

    /**
     * 批量频道页装配（首页多频道区块）：遍历 $cate_nav，按 model+category 位判定每个频道属视频
     * 频道（vod: model!=0 或 category!=1 跳过）还是文章频道（art: model!=1 或 category!=1 跳过），
     * 分别取本频道列表 + 子频道并集 hits 榜。
     * ⚠ 逐频道各发列表/排行查询（非 N+1 收敛版，首页调用需留意开销）。
     * @param string $type 'vod'=视频频道族 / 'art'=文章频道族
     * @param array $cate_nav 导航分类映射（cid => 节点）
     * @param array $catelist 全部分类表（求子分类并集用）
     * @param int $pagesize 每频道列表条数
     * @param int $topsize 每频道热榜条数
     * @return array cid => ['cate','top','list','videos'(vod)|'arts'(art)] 的映射
     */
    public static function channels(string $type, array $cate_nav, array $catelist, int $pagesize, int $topsize): array
    {
        $res = array();
        foreach ($cate_nav as $cid => $nav) {
            if ('vod' === $type) {
                if (($nav['model'] ?? NULL) != 0 || ($nav['category'] ?? NULL) != 1) continue;
                $query = \App\Service\Content\QueryService::channelVideoList($cid, $catelist, 1, $pagesize);
                $ranked = \App\Service\Content\QueryService::rankChannelVideoByHit('hits', $query['child_cids'], 1, $topsize);
                $res[$cid] = array(
                    'cate' => $nav,
                    'top' => self::normalizeRankItems($ranked['items'] ?? array()),
                    'list' => $query['items'] ?: array(),
                    'videos' => intval($query['total'] ?? 0),
                );
                continue;
            }

            if ('art' === $type) {
                if (($nav['model'] ?? NULL) != 1 || ($nav['category'] ?? NULL) != 1) continue;
                $query = \App\Service\Content\QueryService::channelArtList($cid, $catelist, 1, $pagesize);
                $ranked = \App\Service\Content\QueryService::rankChannelArtByHit('hits', $query['child_cids'], 1, $topsize);
                $res[$cid] = array(
                    'cate' => $nav,
                    'top' => self::normalizeRankItems($ranked['items'] ?? array()),
                    'list' => $query['items'] ?: array(),
                    'arts' => intval($query['total'] ?? 0),
                );
            }
        }
        return $res;
    }

    /**
     * 随机推荐区块：先按 vid 降序取一个候选窗口（max(n*5,100) 条 id），洗牌后截前 N（上限 20），
     * 再 findAsc 轻量装配 + 按 $gid 做访问过滤（与主列表口径一致）。
     * 随机只在窗口内——分类越大，长尾 vid 越难被抽中（换取不 ORDER BY RAND() 的全表代价）。
     * @param mixed $cid 分类过滤（0/空=全站窗口）
     * @param int $n 期望条数（>20 时按 20 封顶）
     * @return array|NULL 视频条目数组；候选窗口为空返回 NULL
     */
    public static function random($cid, int $n)
    {
        $pagesize = max($n * 5, 100);
        if ($cid) {
            $arrlist = \App\Repository\RepositoryFactory::vodVid()->find(array('cid' => $cid), array('vid' => -1), 1, $pagesize, 'vid', array('vid'));
        } else {
            $arrlist = \App\Repository\RepositoryFactory::vodVid()->find(array(), array('vid' => -1), 1, $pagesize, 'vid', array('vid'));
        }
        if (!$arrlist) return NULL;
        $vids = array_keys($arrlist);
        $total = min(count($vids), $n > 20 ? 20 : $n);
        shuffle($vids);
        $vidarr = array_slice($vids, 0, $total);
        $list = \App\Service\Content\VideoService::findAsc($vidarr, $total, true);
        if ($list) {
            global $gid;
            video_list_access_filter($list, $gid); // 与主列表口径一致（审计修复）
        }
        return $list;
    }

    /**
     * 时间窗热度区块：从 vod_top 流水表按窗口聚合取 top-N id → findAsc 轻量装配 → 按 $gid 访问过滤。
     * @param string $type 窗口类型（d/w/m 或完整名，经 periodType 归一；未知回落 'hits'）
     * @param int $n 条数
     * @param mixed $cid 分类过滤（NULL=全站）
     * @return array 视频条目数组；窗口内无排行数据返回空数组
     */
    public static function hitPeriod(string $type, int $n, $cid = NULL)
    {
        $hitType = self::periodType($type);
        $ranked = \App\Repository\RepositoryFactory::contentQuery()->hitRankIds('vod_top', 'vid', $cid, $hitType, 1, $n);
        if (empty($ranked['ids'])) return array();
        $list = \App\Service\Content\VideoService::findAsc($ranked['ids'], $n, true);
        if ($list) {
            global $gid;
            video_list_access_filter($list, $gid); // 与主列表口径一致（审计修复）
        }
        return $list ?: array();
    }

    /**
     * 随机海报区块：从置顶分页缓存（stickyFindCacheRand）取候选，只保留 sticky==1（本栏目级），
     * 按 create_date 降序（array_multisort_key）后交给 stickyVideoItems 轻量装配。
     * @param mixed $cid 分类过滤（''/0=不限）
     * @param int $n 候选窗口大小（注意：返回条数 ≤ 窗口内 sticky==1 的数量，不严格等于 $n）
     * @return array|NULL 视频条目数组；候选窗口为空返回 NULL，窗口内无 sticky==1 行返回空数组
     */
    public static function posterRand($cid, int $n)
    {
        $arrlist = \App\Service\Content\VideoService::stickyFindCacheRand($cid, 1, $n);
        if (empty($arrlist)) return NULL;
        $arr2 = array();
        foreach ($arrlist as $val) {
            if (1 == $val['sticky']) {
                $arr2[$val['vid']] = $val;
            }
        }
        if (empty($arr2)) return array();
        $arr2 = array_multisort_key($arr2, 'create_date', FALSE, 'vid');
        return \App\Service\Content\VideoService::stickyVideoItems($arr2, true);
    }

    /**
     * 首页整站推荐区块：取 stickyIndexMap（sticky==1、create_date DESC 映射）前 $n 条，
     * 经 stickyVideoItems 轻量装配。
     * @param int $n 取前 N 条（array_slice 保序截断）
     * @return array 视频条目数组；无置顶数据返回空数组
     */
    public static function stickyIndex(int $n): array
    {
        $arrlist = \App\Service\Content\VideoService::stickyIndexMap();
        if (empty($arrlist)) return array();
        $arrlist = array_slice($arrlist, 0, $n, true);
        return \App\Service\Content\VideoService::stickyVideoItems($arrlist, true);
    }

    /**
     * 榜单条目规整：重排为顺序数组并补 i 连续序号，n 若上游已带（如 rankByHit 写入）则保留不覆盖。
     * @param array $items 原始条目数组（键可能不连续）
     * @return array 顺序数组，每项含 i，n（缺失时= i）
     */
    protected static function normalizeRankItems(array $items): array
    {
        $result = array();
        $i = 0;
        foreach ($items as $item) {
            ++$i;
            $item['i'] = $i;
            if (!isset($item['n'])) {
                $item['n'] = $i;
            }
            $result[] = $item;
        }
        return $result;
    }

    /**
     * 时间窗短形式归一：d/w/m → hits_day/hits_week/hits_month；其余（含完整名与未知值）统一回落 'hits'（全周期）。
     * ⚠ 与 ContentRankTrait::normalizeHitType 不同：那边未命中返回原值，这边未命中返回 'hits'。
     * @param string $type
     * @return string
     */
    protected static function periodType(string $type): string
    {
        if ('d' === $type) return 'hits_day';
        if ('w' === $type) return 'hits_week';
        if ('m' === $type) return 'hits_month';
        return 'hits';
    }
}
