<?php
namespace App\Service\Front;

class ListService
{
    /**
     * 列表页数据装配入口（route/list.php 唯一调用点）：按分类行的 model 分流文章/视频链路。
     * 前置：CateService::read($cid) 取分类行（cache.enable 时读 global $catelist，否则回表 + format）。
     * 页容量：$cate['pagesize'] 非空则用分类自身值，否则取 conf['pagesize']。
     *   ⚠ 现行 cate 表（core/install/install.sql）没有 pagesize 列、后台分类表单也不提交该键
     *   ⇒ 分类级覆盖目前恒不生效，实际总走 conf['pagesize']（要启用需先补列与表单）。
     * 导航子列：仅当分类有上级（cup!=0）时从 $cate_nav[$cup]['sonlist'] 取（模板 extra.sonlist）。
     * @param mixed $cid      分类 ID（路由侧 intval(param(1))）
     * @param mixed $page     页码（路由侧已 min/max 钳制到 1..listsize，防深分页）
     * @param mixed $hit      榜单维度参数（param(3) 取值=已 htmlspecialchars 形态；白名单外的值一律按普通列表走）
     * @param array $conf     站点配置（pagesize/listsize/route 等）
     * @param array $catelist 全部分类行（本方法不用，保留形参以对齐调用点）
     * @param array $cate_nav category_tree() 产出的导航树（取 sonlist）
     * @param string $route_list 列表路由名（conf['route']['list']，供分页 URL 模板）
     * @param array $areaarray/$yeararray/$langarray 地区/年份/语言筛选值列表（conf 的逗号串 explode 而来，仅视频链路用）
     * @return array 命中时 {found:true, type:'art'|'vod', cid, cate, num, page, pagesize, page_url, items, extra}；
     *         分类不存在时 {found:false, reason:'cate_not_found'} ⇒ 路由层据此 404 并 include 404.html
     */
    public static function build($cid, $page, $hit, $conf, $catelist, $cate_nav, $route_list, $areaarray, $yeararray, $langarray)
    {
        $cate = \App\Service\Content\CateService::read($cid);
        if (!$cate) {
            return array('found' => false, 'reason' => 'cate_not_found');
        }

        $cup = $cate['cup'];
        $sonlist = $cup != 0 ? ($cate_nav[$cup]['sonlist'] ?? null) : null;
        $pagesize = empty($cate['pagesize']) ? $conf['pagesize'] : $cate['pagesize'];

        if ('1' == $cate['model']) {
            return self::buildArt($cid, $page, $hit, $pagesize, $cate, $conf, $route_list, $sonlist, $cup);
        }

        return self::buildVod($cid, $page, $hit, $pagesize, $cate, $conf, $route_list, $areaarray, $yeararray, $langarray, $sonlist, $cup);
    }

    /**
     * 文章列表页装配（model==1 分支）：$hit 在 hits/hits_day/hits_week/hits_month 白名单内走 QueryService::rankArtByHit，
     * 否则走 QueryService::listArtByCid + ArtService::countByCid；两路都以 pagesize*conf['listsize'] 给 $num 封顶
     * （= 合法最大页 * 每页条数，路由层页码钳制与此口径配对，勿只改一边）。
     * @param mixed $cid       分类 ID
     * @param mixed $page      页码（已由 build 传入，未再钳制）
     * @param mixed $hit       榜单维度（'' 表示普通列表）
     * @param mixed $pagesize  每页条数（build 里按分类/conf 定的值）
     * @param array $cate      分类行（CateService::read 结果，已 format）
     * @param array $conf      站点配置
     * @param string $route_list 列表路由名
     * @param array|null $sonlist 上级分类的兄弟导航（进 extra 供模板）
     * @param mixed $cup       上级分类 ID（进 extra）
     * @return array {found:true, type:'art', cid, cate, num, page, pagesize, page_url, items, extra:{adlist,sonlist,cup}}
     *         注意 items 可能透传底层的空字符串形态（listByCid 无结果时），模板需按 falsy 处理
     */
    protected static function buildArt($cid, $page, $hit, $pagesize, $cate, $conf, $route_list, $sonlist, $cup)
    {
        $extra = array();
        $allow_hits = array('hits', 'hits_day', 'hits_week', 'hits_month');
        if ($hit && in_array($hit, $allow_hits, true)) {
            $page_url = self::listPageUrl($route_list, $cid, $cate, $extra, $conf, $hit);
            $ranked = \App\Service\Content\QueryService::rankArtByHit($hit, $cid, $page, $pagesize);
            $num = $ranked['total'];
            $artlist = $ranked['items'];
        } else {
            $artlist = \App\Service\Content\QueryService::listArtByCid($cid, $page, $pagesize);
            $page_url = self::listPageUrl($route_list, $cid, $cate, $extra, $conf);
            $acc = \App\Service\Content\ArtService::countByCid($cid);
            $num = $acc > $pagesize * $conf['listsize'] ? $pagesize * $conf['listsize'] : $acc;
        }

        return array(
            'found' => true,
            'type' => 'art',
            'cid' => $cid,
            'cate' => $cate,
            'num' => $num,
            'page' => $page,
            'pagesize' => $pagesize,
            'page_url' => $page_url,
            'items' => $artlist,
            'extra' => array(
                'adlist' => array(),
                'sonlist' => $sonlist,
                'cup' => $cup,
            ),
        );
    }

    /**
     * 视频列表页装配（默认分支）：与 buildArt 同构（$hit 白名单 → rankVideoByHit，否则 listVideoByCid + countByCid，
     * $num 以 pagesize*conf['listsize'] 封顶），额外产 TypeService::typeUrls 的地区/年份/语言筛选链接。
     * ⚠ $req_area/$req_year/$req_lang 在本实现里恒为 ''（列表页不接筛选参数，筛选态由 type.php 路由承担），
     *   这三个键进 extra 只为与模板对齐；typeUrls 会按空筛选态生成 URL。
     * @param mixed $cid       分类 ID
     * @param mixed $page      页码
     * @param mixed $hit       榜单维度（'' = 普通列表）
     * @param mixed $pagesize  每页条数
     * @param array $cate      分类行（已 format）
     * @param array $conf      站点配置
     * @param string $route_list 列表路由名
     * @param array $areaarray/$yeararray/$langarray 筛选值列表
     * @param array|null $sonlist 兄弟导航
     * @param mixed $cup       上级分类 ID
     * @return array {found:true, type:'vod', cid, cate, num, page, pagesize, page_url, items,
     *                extra:{adlist,type_urls,sonlist,cup,req_area,req_lang,req_year}}
     */
    protected static function buildVod($cid, $page, $hit, $pagesize, $cate, $conf, $route_list, $areaarray, $yeararray, $langarray, $sonlist, $cup)
    {
        $extra = array();
        $allow_hits = array('hits', 'hits_day', 'hits_week', 'hits_month');
        $req_area = $req_year = $req_lang = '';
        $type_urls = \App\Service\Front\TypeService::typeUrls($cid, $conf, $areaarray, $yeararray, $langarray, $req_area, $req_lang, $req_year);
        if ($hit && in_array($hit, $allow_hits, true)) {
            $page_url = self::listPageUrl($route_list, $cid, $cate, $extra, $conf, $hit);
            $ranked = \App\Service\Content\QueryService::rankVideoByHit($hit, $cid, $page, $pagesize);
            $num = $ranked['total'];
            $videolist = $ranked['items'];
        } else {
            $videolist = \App\Service\Content\QueryService::listVideoByCid($cid, $page, $pagesize);
            $page_url = self::listPageUrl($route_list, $cid, $cate, $extra, $conf);
            $vcc = \App\Service\Content\VideoService::countByCid($cid);
            $num = $vcc > $pagesize * $conf['listsize'] ? $pagesize * $conf['listsize'] : $vcc;
        }

        return array(
            'found' => true,
            'type' => 'vod',
            'cid' => $cid,
            'cate' => $cate,
            'num' => $num,
            'page' => $page,
            'pagesize' => $pagesize,
            'page_url' => $page_url,
            'items' => $videolist,
            'extra' => array(
                'adlist' => array(),
                'type_urls' => $type_urls,
                'sonlist' => $sonlist,
                'cup' => $cup,
                'req_area' => $req_area,
                'req_lang' => $req_lang,
                'req_year' => $req_year,
            ),
        );
    }

    /**
     * 生成分页 URL 模板（把 {page} 留给分页函数替换）：默认形态 `route-list-{cid}[-{hit}][-{page}]`；
     * 当分类有 alias 且 url_rewrite_on>1 时改用「别名-list_{page}」形态（别名经 xn_urlencode）。
     * @param string $route_list 列表路由名（conf['route']['list']）
     * @param mixed  $cid        分类 ID
     * @param array  $cate       分类行（读 alias）
     * @param array  $extra      附加查询参数（透传给 url() 的 $extra；本链路传空数组）
     * @param array  $conf       站点配置（读 url_rewrite_on）
     * @param string $hit        榜单维度，非空则拼进 URL 段
     * @return string URL 字符串（未转义，模板 {$var} 裸 echo 输出 ⇒ 组成件须来自配置/白名单）
     */
    protected static function listPageUrl($route_list, $cid, $cate, $extra, $conf, $hit = '')
    {
        $page_url = url($route_list . '-' . $cid . '-{page}' . ($hit ? '-' . $hit : ''), $extra);
        if (array_value($cate, 'alias') && array_value($conf, 'url_rewrite_on') > 1) {
            $page_url = url(xn_urlencode($cate['alias']) . '-list_{page}' . ($hit ? '-' . $hit : ''), $extra);
        }
        return $page_url;
    }
}
