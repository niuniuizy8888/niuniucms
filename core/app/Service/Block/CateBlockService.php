<?php
namespace App\Service\Block;

/**
 * 分类/搜索 block 数据提供（从 core/model/block/cate.php、search.php 下沉）
 * 与 VodBlockService/ArtBlockService 同层；block 壳仅做兼容转发
 */
class CateBlockService
{
    /**
     * I 类：分类树形（block_cate_type）
     */
    public static function cateType($arr)
    {
        global $catelist;
        $type = empty($arr['type']) ? '' : $arr['type'];
        $key  = block_key('cate_type', $type);
        return block_cache($key, 3600, function () use ($catelist, $type) {
            if ($type == "vod") {
                $arrlist = arrlist_cond_orderby($catelist, array('model' => 0), array(), 1, 1000);
            } elseif ($type == "art") {
                $arrlist = arrlist_cond_orderby($catelist, array('model' => 1), array(), 1, 1000);
            } else {
                $arrlist = array();
            }
            $arrlist = category_tree_format($arrlist);
            return array_multisort_key($arrlist, 'rank', FALSE, 'cid');
        });
    }

    /**
     * I 类：指定分类列表（block_type_cid）
     */
    public static function typeCid($arr)
    {
        global $catelist;
        $cid  = empty($arr['cid']) ? '' : $arr['cid'];
        $cids = explode(",", $cid);
        $cids = array_filter_empty($cids);
        $key  = block_key('type_cid', $cid);
        return block_cache($key, 3600, function () use ($catelist, $cids) {
            $typelist = array();
            foreach ($cids as $_cid) {
                if (isset($catelist[$_cid]) && is_array($catelist[$_cid])) {
                    $typelist[] = $catelist[$_cid];
                }
            }
            return $typelist;
        });
    }

    /**
     * I 类：分类 ID 列表（block_cate_cids）
     */
    public static function cateCids($arr)
    {
        global $catelist;
        $type = empty($arr['type']) ? '' : $arr['type'];
        $cid  = empty($arr['cid']) ? '' : $arr['cid'];
        $cids = explode(",", $cid);
        $cids = array_filter_empty($cids);
        $key  = block_key('cate_cids', $cid, 't' . $type);
        return block_cache($key, 3600, function () use ($catelist, $cids) {
            $typelist = array();
            foreach ($cids as $_cid) {
                if (isset($catelist[$_cid]) && is_array($catelist[$_cid])) {
                    $typelist[] = $catelist[$_cid];
                }
            }
            return $typelist;
        });
    }

    /**
     * J 类：一维关键词（热门搜索，block_hot_search）
     */
    public static function hotSearch($arr)
    {
        $n   = empty($arr['n']) ? '' : $arr['n'];
        $key = block_key('hot_search', $n);
        return block_cache($key, 3600, function () use ($n) {
            return hot_search($n);
        });
    }
}
