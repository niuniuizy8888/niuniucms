<?php
namespace App\Service\Front;

class CategoryService
{
    /**
     * 前台分类页数据装配（route/category.php 调用）：分类不存在/category 字段空 →
     * found=false + reason（cate_not_found / data_malformation），路由据此走 404/错误分支；
     * 命中后按 $cate['model']=='1' 分流图文(buildArt)/视频(buildVod)。
     * 页大小取分类自带 pagesize 回落 conf['pagesize']；子站清单 $cidarr 取 cate_nav[$cid]['sonlist']。
     * @param int $cid 分类 ID（调用方 intval 过）
     * @param int $page 页码（调用方已钳深分页上界）
     * @param string $hit 排行维度 hits|hits_day|hits_week|hits_month 或 ''（param(3) 取入，默认已转义）
     * @param array $conf 站点配置
     * @param array $catelist 全量分类表 cid=>行
     * @param array $cate_nav 导航树（含 sonlist 子分类）
     * @param string $route_cate 分类路由名（conf['route']['cate']）
     * @return array found=false:{reason} 或 buildArt/buildVod 的视图数据（见其注释）
     */
    public static function build($cid, $page, $hit, $conf, $catelist, $cate_nav, $route_cate)
    {
        $cate = array_value($catelist, $cid);
        if (empty($cate)) {
            return array('found' => false, 'reason' => 'cate_not_found');
        }
        if (empty($cate['category'])) {
            return array('found' => false, 'reason' => 'data_malformation');
        }

        $cup = $cate['cup'] ?? 0;
        $sonlist = $cate_nav[$cid]['sonlist'] ?? null;
        $cidarr = is_array($sonlist) ? array_column($sonlist, 'cid') : array();
        $pagesize = empty($cate['pagesize']) ? $conf['pagesize'] : $cate['pagesize'];

        if ('1' == $cate['model']) {
            return self::buildArt($cid, $page, $hit, $pagesize, $cate, $conf, $cidarr, $sonlist, $cup, $route_cate);
        }

        return self::buildVod($cid, $page, $hit, $pagesize, $cate, $conf, $cidarr, $sonlist, $cup, $route_cate);
    }

    /**
     * 图文分类页装配：$hit 在白名单（hits/hits_day/hits_week/hits_month）时走
     * QueryService::rankChannelArtByHit 排行取数（非 hits 维度 $num 取 ranked 总数；
     * hits 维度取分类文章数并以 pagesize*listsize 封顶），否则走 channelArtListByCids
     * 普通列表（含子分类 $cidarr）。
     * items/arrlistall 等均为渲染数据；⚠ 模板 {$var} 输出层不转义（红线 §二.4），行数据源自库内已转义口径。
     * @param int $cid 分类 ID
     * @param int $page 页码
     * @param string $hit 排行维度或 ''
     * @param int $pagesize 每页条数
     * @param array $cate 分类行（会被回填 arts 计数）
     * @param array $conf 站点配置（listsize 等）
     * @param array $cidarr 本分类+子分类 cid 列表
     * @param array|null $sonlist 子分类导航（进 extra 供模板）
     * @param int $cup 顶级分类 ID（分类行 cup 字段）
     * @param string $route_cate 分类路由名
     * @return array {found:true, type:'art', cid, cate, num(可展示条数), page, pagesize,
     *                page_url, items, extra:{view:'art_category', extra_json, arrlistall, sonlist, cup}}
     */
    protected static function buildArt($cid, $page, $hit, $pagesize, $cate, $conf, $cidarr, $sonlist, $cup, $route_cate)
    {
        $allow_hits = array('hits', 'hits_day', 'hits_week', 'hits_month');
        if ($hit && in_array($hit, $allow_hits, true)) {
            $page_url = self::categoryPageUrl($route_cate, $cid, $cate, $conf, $hit);
            $ranked = \App\Service\Content\QueryService::rankChannelArtByHit($hit, $cidarr, $page, $pagesize);
            $arrlistall = \App\Service\Content\CateService::channelArtCache($cid, $pagesize);
            $cate['arts'] = intval(\App\Service\Content\ArtService::countByCid($cidarr));
            if ('hits' != $hit) {
                $num = $ranked['total'];
            } else {
                $num = $cate['arts'] > $pagesize * $conf['listsize'] ? $pagesize * $conf['listsize'] : $cate['arts'];
            }
            $artlist = $ranked['items'];
        } else {
            $query = \App\Service\Content\QueryService::channelArtListByCids($cid, $cidarr, $page, $pagesize);
            $arts = $query['total'];
            $arrlistall = $query['channel_cache'];
            $cate['arts'] = $arts;
            $artlist = $query['items'];
            $page_url = self::categoryPageUrl($route_cate, $cid, $cate, $conf);
            $num = $arts > $pagesize * $conf['listsize'] ? $pagesize * $conf['listsize'] : $arts;
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
                'view' => 'art_category',
                'extra_json' => array('sonlist' => $sonlist),
                'arrlistall' => $arrlistall,
                'sonlist' => $sonlist,
                'cup' => $cup,
            ),
        );
    }

    /**
     * 视频分类页装配：与 buildArt 同构——$hit 在白名单走 rankChannelVideoByHit 排行
     * （非 hits 维度 $num 取 ranked 总数；hits 维度取分类视频数并以 pagesize*listsize 封顶），
     * 否则 channelVideoListByCids 普通列表（含子分类 $cidarr）。
     * @param int $cid 分类 ID
     * @param int $page 页码
     * @param string $hit 排行维度或 ''
     * @param int $pagesize 每页条数
     * @param array $cate 分类行（会被回填 videos 计数）
     * @param array $conf 站点配置（listsize 等）
     * @param array $cidarr 本分类+子分类 cid 列表
     * @param array|null $sonlist 子分类导航（进 extra 供模板）
     * @param int $cup 顶级分类 ID（分类行 cup 字段）
     * @param string $route_cate 分类路由名
     * @return array {found:true, type:'vod', cid, cate, num, page, pagesize, page_url, items,
     *                extra:{view:'category', extra_json, arrlistall, sonlist, cup}}
     */
    protected static function buildVod($cid, $page, $hit, $pagesize, $cate, $conf, $cidarr, $sonlist, $cup, $route_cate)
    {
        $allow_hits = array('hits', 'hits_day', 'hits_week', 'hits_month');
        if ($hit && in_array($hit, $allow_hits, true)) {
            $page_url = self::categoryPageUrl($route_cate, $cid, $cate, $conf, $hit);
            $ranked = \App\Service\Content\QueryService::rankChannelVideoByHit($hit, $cidarr, $page, $pagesize);
            $arrlistall = \App\Service\Content\CateService::channelVideoCache($cid, $pagesize);
            $cate['videos'] = intval(\App\Service\Content\VideoService::countByCid($cidarr));
            if ('hits' != $hit) {
                $num = $ranked['total'];
            } else {
                $num = $cate['videos'] > $pagesize * $conf['listsize'] ? $pagesize * $conf['listsize'] : $cate['videos'];
            }
            $videolist = $ranked['items'];
        } else {
            $query = \App\Service\Content\QueryService::channelVideoListByCids($cid, $cidarr, $page, $pagesize);
            $videos = $query['total'];
            $arrlistall = $query['channel_cache'];
            $cate['videos'] = $videos;
            $videolist = $query['items'];
            $page_url = self::categoryPageUrl($route_cate, $cid, $cate, $conf);
            $num = $videos > $pagesize * $conf['listsize'] ? $pagesize * $conf['listsize'] : $videos;
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
                'view' => 'category',
                'extra_json' => array('sonlist' => $sonlist),
                'arrlistall' => $arrlistall,
                'sonlist' => $sonlist,
                'cup' => $cup,
            ),
        );
    }

    /**
     * 分类页翻页 URL 模板：默认 `{route_cate}-{cid}-{page}[-{hit}]`；
     * url_rewrite_on>1 且分类配了 alias 时改用别名形态 `{urlencode(alias)}-list_{page}[-{hit}]`。
     * {page} 占位符由 pagination() 替换。
     * @param string $route_cate 分类路由名
     * @param int $cid 分类 ID
     * @param array $cate 分类行（读 alias）
     * @param array $conf 站点配置（读 url_rewrite_on）
     * @param string $hit 排行维度（非空进 URL 段）
     * @return string 带 {page} 占位的翻页 URL 模板
     */
    protected static function categoryPageUrl($route_cate, $cid, $cate, $conf, $hit = '')
    {
        $page_url = url($route_cate . '-' . $cid . '-{page}' . ($hit ? '-' . $hit : ''));
        if ($conf['url_rewrite_on'] > 1 && !empty($cate['alias'])) {
            $page_url = url(xn_urlencode($cate['alias']) . '-list_{page}' . ($hit ? '-' . $hit : ''));
        }
        return $page_url;
    }
}
