<?php
namespace App\Service\Front;

class TagService
{
    /**
     * 装配标签云/标签列表页数据（route/tag.php 列表分支）。
     * 总数 >0 才查询，否则 taglist 为 null（Content\TagService::find 空结果返回 NULL，非空数组）。
     *
     * @param int   $page 页码（路由 intval(param(2,1)) 传入）
     * @param array $conf 站点配置（读 tagsize/listsize/route.tag）
     * @return array {page, pagesize, count, num(截断后展示条数), page_url, taglist(array|null), adlist}
     */
    public static function buildList($page, $conf)
    {
        $pagesize = $conf['tagsize'];
        $count = \App\Service\Content\TagService::count();
        $taglist = $count ? \App\Service\Content\TagService::find($page, $pagesize) : null;
        $page_url = url($conf['route']['tag'] . '-list-{page}', array());
        $num = $count > $pagesize * $conf['listsize'] ? $pagesize * $conf['listsize'] : $count;

        return array(
            'page' => $page,
            'pagesize' => $pagesize,
            'count' => $count,
            'num' => $num,
            'page_url' => $page_url,
            'taglist' => $taglist,
            'adlist' => array(),
        );
    }

    /**
     * 装配标签详情页数据（route/tag.php 详情分支）：按 tagid 取标签（走缓存）后
     * 经 RelationService::buildTagMixedList 组装该标签下的混合内容列表。
     * 标签不存在时返回 found=FALSE 短路（**不返回可渲染数据**），由路由据此出 404/提示。
     *
     * @param int   $tagid 标签主键（路由已 intval，页码由调用方钳制到 1..conf.listsize）
     * @param int   $page  页码
     * @param array $conf  站点配置（读 pagesize/listsize/route.tag）
     * @return array found=TRUE 时 {found, tagid, tags, page, pagesize, num, page_url, videolist, cid:0, adlist}；
     *               未命中时 {found:false, reason:'tag_not_found'}
     */
    public static function buildDetail($tagid, $page, $conf)
    {
        $pagesize = $conf['pagesize'];
        $tags = \App\Service\Content\TagService::readByIdCache($tagid);
        if (empty($tags)) {
            return array('found' => false, 'reason' => 'tag_not_found');
        }

        $page_url = url($conf['route']['tag'] . '-' . $tagid . '-{page}', array());
        $num = $tags['count'] > $pagesize * $conf['listsize'] ? $pagesize * $conf['listsize'] : $tags['count'];
        // 越界页钳制到末页（与分页条口径一致，避免「分页条显示末页、列表为空」）
        $page = min(max(1, intval($page)), max(1, (int)ceil($num / max(1, $pagesize))));

        $videolist = \App\Service\Content\RelationService::buildTagMixedList($tagid, $tags['name'], $page, $pagesize);

        return array(
            'found' => true,
            'tagid' => $tagid,
            'tags' => $tags,
            'page' => $page,
            'pagesize' => $pagesize,
            'num' => $num,
            'page_url' => $page_url,
            'videolist' => $videolist,
            'cid' => 0,
            'adlist' => array(),
        );
    }
}
