<?php
namespace App\Service\Content;

/**
 * VideoStickyTrait — VideoService 方法簇拆分（职责文件化，调用面不变）
 */
trait VideoStickyTrait
{
    /**
     * 置顶关联表（vod_sticky）通用列表查询 —— RepositoryFactory::stickyVod()->find 薄封装。
     * DbTableRepository::find 语义：$orderby 值 1=ASC 其余 DESC；$key 非空按该列值重建索引（默认 vid）；
     * $col 空=SELECT *（留痕 sql_star_select）；$d 连接选择器（NULL=默认连接）。
     * @param array $cond @param array $orderby @param int $page @param int $pagesize 默认 2000（近全量） @param string $key @param array $col @param mixed $d
     * @return array 行列表；无结果空 array
     */
    public static function stickyFind(array $cond = array(), array $orderby = array('vid' => -1), int $page = 1, int $pagesize = 2000, string $key = 'vid', array $col = array(), $d = NULL)
    {
        return \App\Repository\RepositoryFactory::stickyVod()->find($cond, $orderby, $page, $pagesize, $key, $col, $d);
    }

    /**
     * 全量置顶行缓存（sticky_video_list，TTL 1800s + 请求内 static 双层）。
     * 空结果不写跨请求缓存、只在请求内记 NULL ⇒ 同请求后续调用不再打库。
     * 失效靠 TTL 或 stickyUpdateByVid/stickyDelete 的显式清理；stickyChange 不在此清理（缓存滞后窗口）。
     * @return array|null 置顶行列表（vid 键）；无数据返回 NULL
     */
    public static function stickyFindCache()
    {
        $key = 'sticky_video_list';
        static $cache = array();
        if (isset($cache[$key])) return $cache[$key];
        $arr = cache_get($key);
        if (empty($arr)) { // 缓存的空数组也视为未命中，避免空值遮蔽新推荐
            $arr = self::stickyFind(array(), array('vid' => -1), 1, 2000);
            $arr and cache_set($key, $arr, 1800);
        }
        $cache[$key] = $arr ? $arr : NULL;
        return $cache[$key];
    }

    /**
     * 分页维度置顶列表缓存：缓存键含 sticky_video_ver 版本令牌 + cid/page/pagesize（TTL 3600s + 请求内 static）。
     * 版本令牌由 stickyUpdateByVid/stickyDelete 用 time() 推进 ⇒ 变更即全量分页缓存失效（键换代）；
     * 读令牌用 cache_get（本方法为列表装配路径，非跨 worker 强一致场景）。
     * @param int|string $cid 分类过滤（''=不限，array_filter_empty 剥除）
     * @param int $page @param int $pagesize
     * @return array|null 该页置顶行；无数据 NULL（NULL 不落跨请求缓存）
     */
    public static function stickyFindCacheRand($cid = '', int $page = 1, int $pagesize = 20)
    {
        $ver = (int)cache_get('sticky_video_ver');
        $key = 'sticky_video_list_' . $ver . '_' . $pagesize . '_' . $cid . '_' . $page;
        static $cache = array();
        if (isset($cache[$key])) return $cache[$key];
        $where = array_filter_empty(array('cid' => $cid));
        $arr = cache_get($key);
        if (NULL === $arr) {
            $arr = self::stickyFind($where, array('vid' => -1), $page, $pagesize);
            $arr and cache_set($key, $arr, 3600);
        }
        $cache[$key] = $arr ? $arr : NULL;
        return $cache[$key];
    }

    /**
     * 首页整站置顶映射：从 stickyFindCache 取 sticky==1 的行，按 create_date DESC、vid 次序重排。
     * @return array|null 无置顶缓存返回 NULL；缓存有数据但无 sticky==1 行返回空 array
     */
    public static function stickyIndexMap()
    {
        $arrlist = self::stickyFindCache();
        if (empty($arrlist)) return NULL;
        $arr = array();
        foreach ($arrlist as $val) {
            if (1 == $val['sticky']) {
                $arr[$val['vid']] = $val;
            }
        }
        return empty($arr) ? array() : array_multisort_key($arr, 'create_date', FALSE, 'vid');
    }

    /**
     * 分类列表页置顶区映射：依赖 global $catelist_show（展示中的分类树）。
     * 从置顶缓存按层级分桶：sticky==3（全站，任意分类可见）、sticky==2（同 cup 大类下展示分类的栏目置顶）、
     * sticky==1（仅当 $cid 自身是展示栏目时取本栏目置顶）；各桶按 create_date DESC、vid 重排后以 3+2+1 优先级并集合并（同 vid 高档位优先）。
     * @param int $cid 当前分类 ID
     * @return array|null $cid 不在展示分类表、或无置顶缓存时返回 NULL；否则映射（可为空 array）
     */
    public static function stickyListMap($cid)
    {
        global $catelist_show;
        $cate = isset($catelist_show[$cid]) ? $catelist_show[$cid] : NULL;
        if (empty($cate)) return NULL;
        $arrlist = self::stickyFindCache();
        if (empty($arrlist)) return NULL;

        $cids = array();
        foreach ($catelist_show as $val) {
            if ($val['type'] && $val['display']) {
                if (1 == $cate['category']) {
                    if ($val['cup'] == $cid) $cids[] = $val['cid'];
                } else {
                    if ($val['cup'] == $cate['cup']) $cids[] = $val['cid'];
                }
            }
        }

        $sticky1 = array();
        $sticky2 = array();
        $sticky3 = array();
        if (1 == $cate['category']) {
            foreach ($arrlist as $val) {
                if (in_array($val['cid'], $cids) && 2 == $val['sticky']) {
                    $sticky2[$val['vid']] = $val;
                } elseif (3 == $val['sticky']) {
                    $sticky3[$val['vid']] = $val;
                }
            }
        } else {
            foreach ($arrlist as $val) {
                if ($cate['cid'] == $val['cid'] && 1 == $val['sticky']) {
                    $sticky1[$val['vid']] = $val;
                } elseif (in_array($val['cid'], $cids) && 2 == $val['sticky']) {
                    $sticky2[$val['vid']] = $val;
                } elseif (3 == $val['sticky']) {
                    $sticky3[$val['vid']] = $val;
                }
            }
        }

        $sticky3 = empty($sticky3) ? array() : array_multisort_key($sticky3, 'create_date', FALSE, 'vid');
        $sticky2 = empty($sticky2) ? array() : array_multisort_key($sticky2, 'create_date', FALSE, 'vid');
        $sticky1 = empty($sticky1) ? array() : array_multisort_key($sticky1, 'create_date', FALSE, 'vid');
        return $sticky3 + $sticky2 + $sticky1;
    }

    /**
     * 置顶映射 → 视频行集合：取 map 内 vid 去重后走 ContentQueryTrait::find（按 vid DESC、format 加工；$light 控制大字段列裁剪）。
     * 与 vod 主表已删但 vod_sticky 残留的行会在 find 阶段自然丢失（不保证与入参条数一致）。
     * @param array $stickyMap vid 键的置顶行映射（stickyIndexMap/stickyListMap 产物）
     * @param bool $light TRUE=轻量列（列表/区块用），FALSE=含播放列
     * @return array 视频行列表（vid 键）；入参空、无有效 vid 或查询无命中返回空 array
     */
    public static function stickyVideoItems(array $stickyMap, bool $light = TRUE): array
    {
        if (empty($stickyMap)) return array();
        $vids = array_unique(arrlist_values($stickyMap, 'vid'));
        if (empty($vids)) return array();
        $items = self::find($vids, count($vids), TRUE, $light);
        return $items ?: array();
    }

    /**
     * 置推荐写路径：更新 vod.sticky + REPLACE vod_sticky（cid/vid/sticky/create_date=time）。
     * 写入后清 sticky_video_list 并推进 sticky_video_ver（首页/分页置顶缓存即时换代失效）。
     * @param int $vid 视频 ID（本方法不校验存在性，靠调用方传入 $video 非空把关）
     * @param int $sticky 推荐级别（写两表）
     * @param array $video 视频整行（取 cid/vid；空数组短路返回 FALSE）
     * @return int|false REPLACE 结果 lastInsertId（执行失败坍缩为 0，不可与成功 0 区分）；$video 为空返回 FALSE
     */
    public static function stickyChange($vid, $sticky, array $video)
    {
        $time = $GLOBALS['time'] ?? time();
        if (empty($video)) return FALSE;
        self::update($vid, array('sticky' => $sticky));
        $r = \App\Repository\RepositoryFactory::stickyVod()->replace(array(
            'cid' => $video['cid'],
            'vid' => $video['vid'],
            'sticky' => $sticky,
            'create_date' => $time,
        ));
        cache_delete('sticky_video_list');
        cache_set('sticky_video_ver', time(), 86400);
        return $r;
    }

    /**
     * 视频换分类后同步置顶关联行：vod_sticky.cid 按 vid 条件更新；随后清 sticky_video_list 并推进 sticky_video_ver（分页置顶缓存整体换代失效）。
     * @param mixed $vid vod_sticky 主键 vid（不做存在性校验，vid 不存在则影响 0 行）
     * @param mixed $newcid 新分类 ID
     * @return int|false 影响行数（update 无 where/空数据才 FALSE，这里 cond 恒非空）
     */
    /**
     * 取消推荐/删除置顶关联：先 video__read 取视频行（不存在直接 FALSE）；
     * sticky==3 时递减 conf.index_stickys 并 setting_set 持久化；
     * video.sticky 非 0 时回写 vod.sticky=0 并清 sticky_video_list + 推进 sticky_video_ver；
     * 最后 DELETE vod_sticky WHERE vid（无论此前是否推荐都执行，未推荐时通常返回 0）。
     * @param mixed $vid 视频 ID
     * @return int|false 关联表删除影响行数；视频行不存在返回 FALSE
     */
    public static function stickyDelete($vid)
    {
        global $config;
        $video = video__read(array('vid' => $vid));
        if (empty($video)) return FALSE;
        if (3 == $video['sticky']) {
            $config['index_stickys'] -= 1;
            setting_set('conf', $config);
        }
        if ($video['sticky']) {
            self::update($vid, array('sticky' => 0));
            cache_delete('sticky_video_list');
            cache_set('sticky_video_ver', time(), 86400);
        }
        return \App\Repository\RepositoryFactory::stickyVod()->delete(array('vid' => $vid));
    }

    }
