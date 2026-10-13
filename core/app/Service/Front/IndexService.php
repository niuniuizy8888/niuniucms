<?php
namespace App\Service\Front;

class IndexService
{
    /**
     * 首页视图数据装配（route/index.php 经 _include 编译管线调用后 extract 进模板）。
     * 口径：
     *  - pagesize：首页专属窗口，clamp 到 [6,60]；conf['index_pagesize']>0 时覆盖，
     *    否则 min(conf['pagesize'],24)——R7-P09 与列表页解耦（runtime_conf 曾把
     *    pagesize 顶到 200，首页一次渲染 200+ 张远程海报）；
     *  - 栏目收集：$cid_count != 0 时取 catelist 中 type==1 && nav_display==1 && category==0
     *    的视频栏目 cids 与 videos 总量；文章栏目 art_cids 仅取与 cids 同 cid 且 model==1 者；
     *    $cid_count==0 ⇒ 两组 cids 为空 ⇒ videolist/artlist 直接空数组（homeVideoList/homeArtList 短路）；
     *  - num = min(videos, pagesize*conf['listsize'])，供 index_page 分页条；
     *  - adlist 恒空数组（模板占位）。
     * @param array $catelist 分类列表（cate_list_cache）
     * @param int $cid_count 非 0=启用首页视频栏目聚合（路由侧传入）
     * @param array $conf 运行时 conf（pagesize/index_pagesize/linksize/listsize/route）
     * @param int $page 当前页
     * @param string $route 路由前缀（拼 {page} 翻页 URL）
     * @return array 模板变量集（签名已声明 array）：videolist/artlist 为装配行数组（无数据=空数组）
     */
    public static function build(array $catelist, int $cid_count, array $conf, int $page, string $route): array
    {
        $extra = array();
        $cid = 0;
        // R7-P09：首页「最新视频」块与列表页 pagesize 解耦。
        // 运行期 runtime_conf 把 pagesize 覆盖为 200（列表页用），此前首页因此一次渲染 200 张远程海报
        // （实测首页 235 <img>，其中该块 204 张、>70% 字节）。首页默认 24 条，可经 index_pagesize 覆盖。
        $pagesize = intval($conf['pagesize']);
        $indexPagesize = intval($conf['index_pagesize'] ?? 0);
        if ($indexPagesize <= 0) $indexPagesize = min($pagesize, 24);
        $pagesize = max(6, min($indexPagesize, 60));
        $videolist = $vidlist = NULL;
        $videos = 0;
        $cids = array();
        if ($cid_count != 0) {
            foreach ($catelist as $key => $val) {
                if (1 == $val['type'] && 1 == $val['nav_display'] && 0 == $val['category']) {
                    $cids[] = $val['cid'];
                    $videos += $val['videos'];
                }
            }
        }

        $art_cids = array();
        if (!empty($catelist) && is_array($catelist)) {
            foreach ($catelist as $key => $val) {
                if (1 == $val['model'] && in_array($val['cid'], $cids)) {
                    $art_cids[] = $val['cid'];
                }
            }
        }

        $videolist = \App\Service\Content\QueryService::homeVideoList($cids, $page, $pagesize);
        $artlist = \App\Service\Content\QueryService::homeArtList($art_cids, $page, $pagesize);
        $linklist = \App\Service\Content\LinkService::listCache(1, intval($conf['linksize']));
        $page_url = url($route . '-{page}', $extra);
        $num = $videos > $pagesize * $conf['listsize'] ? $pagesize * $conf['listsize'] : $videos;
        $index_page = index_page($page_url, $num, $page, $pagesize);
        $adlist = array();

        return array(
            'cid' => $cid,
            'num' => $num,
            'page' => $page,
            'pagesize' => $pagesize,
            'page_url' => $page_url,
            'videolist' => $videolist,
            'artlist' => $artlist,
            'linklist' => $linklist,
            'index_page' => $index_page,
            'adlist' => $adlist,
        );
    }
}
