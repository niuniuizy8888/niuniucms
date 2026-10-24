<?php
namespace App\Service\Front;

class VodService
{
    /**
     * 前台播放页数据装配（route/vod.php 调用）。found=false 三分支：
     * video_not_found / cate_not_found（路由 404）、redirect（别名访问且 $alias_cid 与真实
     * cid 不符，url=规范链接，路由 302 过去）。
     * ⚠ 副作用：incViews 加浏览量、recordTopView 记录、es_on 时写 ES（ElasticService::insertOne）、
     * ensureVideoTagsAndRelated 补标签/关联。user_access 为分类 allowread 判定（路由据此拦未授权：
     * JSON 403 / HTML 跳登录）；like_state/favorite_state：'un'=该用户已存在点赞/收藏记录
     * （未登录时 check 恒 false ⇒ ''）；adlist 恒空数组（模板占位）；play_urls 取 play_data[0]['url']。
     * @param int $vid 视频 ID
     * @param int $uid 当前用户 ID（点赞/收藏态查询）
     * @param int $gid 当前用户组 ID（allowread 判定）
     * @param array $conf 站点配置（url_rewrite_on/es_on）
     * @param array $catelist 全量分类表 cid=>行
     * @param array $cate_nav 导航树（取 cup 顶层的 sonlist）
     * @param int $alias_cid 别名解析出的分类 ID（0=非别名访问）
     * @return array {found:true, video, cid, cate, cup, sonlist, user_access, like_state,
     *                favorite_state, adlist, likelist, play_urls} 或 {found:false, reason[, url]}
     */
    public static function build(int $vid, int $uid, int $gid, array $conf, array $catelist, array $cate_nav, int $alias_cid = 0): array
    {
        $video = \App\Service\Content\VideoService::read($vid);
        if (!$video) {
            return array('found' => false, 'reason' => 'video_not_found');
        }
        if ($conf['url_rewrite_on'] > 1 && !empty($alias_cid) && $alias_cid != $video['cid']) {
            return array('found' => false, 'reason' => 'redirect', 'url' => $video['url']);
        }
        $cid = $video['cid'];
        $cate = isset($catelist[$cid]) ? $catelist[$cid] : NULL;
        if (!$cate) {
            return array('found' => false, 'reason' => 'cate_not_found');
        }
        $cup = $cate['cup'];
        // 上线前审计 N-DYN-01：空分类/导航缺失时 sonlist 不得为 null（模板 foreach 在 PHP 8+ TypeError 500）
        $sonlist = array();
        if ($cup != 0 && is_array($cate_nav) && isset($cate_nav[$cup]) && is_array($cate_nav[$cup])) {
            $sonlist = isset($cate_nav[$cup]['sonlist']) && is_array($cate_nav[$cup]['sonlist'])
                ? $cate_nav[$cup]['sonlist'] : array();
        }
        $user_access = \App\Service\Content\CateService::accessUser($cid, $gid, 'allowread');
        $like_state = \App\Service\Content\LikeService::check($vid, $uid, 0) ? 'un' : '';
        $favorite_state = \App\Service\Content\FavoriteService::check($vid, $uid, 0) ? 'un' : '';
        \App\Service\Content\VideoService::incViews($vid);
        \App\Service\Content\VideoService::recordTopView($vid, $cid);
        !empty($conf['es_on']) and \App\Service\System\ElasticService::insertOne($vid, $video['name']);
        $adlist = array();
        $likelist = \App\Service\Content\RelationService::ensureVideoTagsAndRelated($video, $vid, $cid);
        $play_urls = $video['play_data'][0]['url'] ?? '';

        return array(
            'found' => true,
            'video' => $video,
            'cid' => $cid,
            'cate' => $cate,
            'cup' => $cup,
            'sonlist' => $sonlist,
            'user_access' => $user_access,
            'like_state' => $like_state,
            'favorite_state' => $favorite_state,
            'adlist' => $adlist,
            'likelist' => $likelist,
            'play_urls' => $play_urls,
        );
    }
}
