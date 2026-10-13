<?php
namespace App\Service\Front;

class ArtService
{
    /**
     * 装配文章详情页数据（route/art.php）。多处 found=FALSE 短路，交由路由做 404/跳转/无权限处理：
     *  · 文章不存在 → art_not_found；
     *  · url_rewrite_on>1 且别名分类与实际 cid 不符 → redirect（带规范 url，供路由跳转，防别名串页）；
     *  · 分类缺失 → cate_not_found；
     *  · 受限栏目 CateService::accessUser(allowread) 不过 → forbidden（权限门禁 fail-closed）。
     * 通过后顺带：incViews 计数 + recordTopView 榜单 + 点赞/收藏态（按 uid）+ member_action 场景令牌 + 相关文章。
     * 注意：返回内容进前台模板 `{eval}` 关闭下的 `{$var}` 裸 echo，模板输出层不转义（安全红线 §二.4），
     * 转义边界在入库侧，本函数不做输出转义。
     *
     * @param int   $aid        文章主键
     * @param int   $uid        当前会员 uid（0=游客，用于点赞/收藏态与令牌签发）
     * @param int   $gid        当前用户组 gid（用于栏目 allowread 判定）
     * @param array $conf       站点配置（读 url_rewrite_on）
     * @param array $catelist   全部分类 gid/cid=>行 映射（路由全局传入）
     * @param array $cate_nav   分类导航结构（取父级 cup 的 sonlist 作侧栏）
     * @param int   $alias_cid  URL 别名解析出的分类 id（0=非别名访问）
     * @return array found=TRUE 时含 art/cid/cate/cup/sonlist/like_state/favorite_state/safe_token/adlist/likelist；
     *               否则 {found:false, reason:..., url?}
     */
    public static function build(int $aid, int $uid, int $gid, array $conf, array $catelist, array $cate_nav, int $alias_cid = 0): array
    {
        $art = \App\Service\Content\ArtService::read($aid);
        if (!$art) {
            return array('found' => false, 'reason' => 'art_not_found');
        }
        if ($conf['url_rewrite_on'] > 1 && !empty($alias_cid) && $alias_cid != $art['cid']) {
            return array('found' => false, 'reason' => 'redirect', 'url' => $art['url']);
        }
        $cid = $art['cid'];
        $cate = isset($catelist[$cid]) ? $catelist[$cid] : NULL;
        if (!$cate) {
            return array('found' => false, 'reason' => 'cate_not_found');
        }
        // 受限栏目访问控制（与视频详情同口径；上线前审计修复）
        if (!\App\Service\Content\CateService::accessUser($cid, $gid, 'allowread')) {
            return array('found' => false, 'reason' => 'forbidden');
        }
        $cup = $cate['cup'];
        $sonlist = $cup != 0 ? ($cate_nav[$cup]['sonlist'] ?? null) : null;
        $like_state = \App\Service\Content\LikeService::check($aid, $uid, 1) ? 'un' : '';
        $favorite_state = \App\Service\Content\FavoriteService::check($aid, $uid, 1) ? 'un' : '';
        $safe_token = bull_token_set($uid, 'member_action');
        $adlist = array();
        \App\Service\Content\ArtService::incViews($aid);
        \App\Service\Content\ArtService::recordTopView($aid, $cid);
        $likelist = \App\Service\Content\RelationService::buildArtRelated($art);

        return array(
            'found' => true,
            'art' => $art,
            'cid' => $cid,
            'cate' => $cate,
            'cup' => $cup,
            'sonlist' => $sonlist,
            'like_state' => $like_state,
            'favorite_state' => $favorite_state,
            'safe_token' => $safe_token,
            'adlist' => $adlist,
            'likelist' => $likelist,
        );
    }
}
