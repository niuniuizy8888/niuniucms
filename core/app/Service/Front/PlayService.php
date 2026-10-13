<?php
namespace App\Service\Front;

class PlayService
{
    /**
     * 播放页数据聚合（route/play.php 唯一入口）：VideoService::read 取片 → 别名分类不一致时
     * 返回 redirect 交调用方 301 → 分类存在性与 allowread 权限（不过则 forbidden，fail-closed）→
     * 线路 $play_from/集数 $ep 双 1 基下标校验 → 解析播放器地址（resolvePlayerUrl + player_parse_kind，
     * bad 形态直接 player_not_found 404）→ 点赞/收藏态、member_action 场景 safe_token、incViews +
     * recordTopView（请求尾合并落库）、es_on 时同步 ES → 组装 found=true 大数组（含 play_list/player/
     * likelist 等渲染字段）。
     * @param int $vid 视频 ID（调用点已 intval）
     * @param int $play_from 播放线路序号（1 基）
     * @param int $ep 集数（1 基）
     * @param int $uid 当前用户 ID
     * @param int $gid 用户组 ID（权限判定输入）
     * @param array $conf 站点配置
     * @param array $catelist 分类表 cid=>行
     * @param array $cate_nav 分类导航树（取 cup 子列）
     * @param int $alias_cid URL 别名解析出的分类 ID（0=无别名）
     * @return array found=false+reason（video_not_found/redirect/cate_not_found/forbidden/
     * play_from_not_found/episode_not_found/player_not_found）或 found=true+渲染字段全集
     */
    public static function build($vid, $play_from, $ep, $uid, $gid, $conf, $catelist, $cate_nav, $alias_cid = 0)
    {
        $video = \App\Service\Content\VideoService::read($vid);
        if (!$video) {
            return array('found' => false, 'reason' => 'video_not_found');
        }
        if ($conf['url_rewrite_on'] > 1 && !empty($alias_cid) && $alias_cid != $video['cid']) {
            return array('found' => false, 'reason' => 'redirect', 'url' => $video['url']);
        }

        $cid = $video['cid'];
        $cate = isset($catelist[$cid]) ? $catelist[$cid] : null;
        if (!$cate) {
            return array('found' => false, 'reason' => 'cate_not_found');
        }

        $cup = $cate['cup'];
        $sonlist = $cup != 0 ? ($cate_nav[$cup]['sonlist'] ?? null) : null;
        $user_access = \App\Service\Content\CateService::accessUser($cid, $gid, 'allowread');
        if (!$user_access) {
            return array(
                'found' => false,
                'reason' => 'forbidden',
                'cid' => $cid,
                'cate' => $cate,
            );
        }

        $from_index = $play_from - 1;
        $ep_index = $ep - 1;
        if (!isset($video['play_data'][$from_index])) {
            return array('found' => false, 'reason' => 'play_from_not_found');
        }

        $play_list = $video['play_data'][$from_index]['info'] ?? array();
        if (!isset($play_list[$ep_index])) {
            return array('found' => false, 'reason' => 'episode_not_found');
        }

        $froms = $video['play_data'][$from_index]['from'];
        $player_url = self::resolvePlayerUrl($conf, $froms);
        // 播放器解析地址的三种形态（站内内置 / 站内播放页 / 第三方解析）统一由 player_parse_kind() 判定；
        // 旧实现只放行 http/https，导致「默认播放器改成本站播放器」根本无法配置（留空即整页 404）。
        $player_kind = player_parse_kind($player_url);
        if ('bad' === $player_kind) {
            return array('found' => false, 'reason' => 'player_not_found');
        }
        // on=0 是后台「弹幕播放器=开启」（该配置族沿用 0=开启 的历史口径）；
        // 开启弹幕、或该线路本就选了内置播放器时，前台内联渲染本站播放器，不外链。
        $danmu_on = isset($conf['danmu']['on']) ? intval($conf['danmu']['on']) : 0;
        $player_local = ('inline' === $player_kind) || 0 === $danmu_on;

        $like_state = \App\Service\Content\LikeService::check($vid, $uid, 0) ? 'un' : '';
        $favorite_state = \App\Service\Content\FavoriteService::check($vid, $uid, 0) ? 'un' : '';
        $safe_token = bull_token_set($uid, 'member_action');
        \App\Service\Content\VideoService::incViews($vid);
        \App\Service\Content\VideoService::recordTopView($vid, $cid);
        !empty($conf['es_on']) and \App\Service\System\ElasticService::insertOne($vid, $video['name']);

        $play_json = xn_json_encode($play_list);
        $play_name = $play_list[$ep_index]['name'];
        $play_url = $play_list[$ep_index]['play_url'];
        // 内置播放器不出 iframe，$player 留空；其余形态按占位符展开（无占位符=「前缀+直链」老契约）
        $player = $player_local ? '' : player_parse_build($player_url, $vid, $play_from, $ep, $play_url);
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
            'safe_token' => $safe_token,
            'play_json' => $play_json,
            'play_list' => $play_list,
            'play_name' => $play_name,
            'play_url' => $play_url,
            'froms' => $froms,
            'player' => $player,
            'player_local' => $player_local,
            'player_kind' => $player_kind,
            'likelist' => $likelist,
            'play_urls' => $play_urls,
            'adlist' => array(),
        );
    }

    /**
     * 取该播放线路的播放器解析地址：conf['vodplayer'][<froms>]['parse'] 优先 → default 线路 →
     * vodplayer 首个含 parse 的条目 → 皆无返回空串（空串由 build 的 player_parse_kind 判 bad 走 404）。
     * @param array $conf 站点配置
     * @param string $froms 线路标识（play_data 的 from 名）
     * @return string 解析地址或空串
     */
    protected static function resolvePlayerUrl($conf, $froms)
    {
        if (isset($conf['vodplayer'][$froms]['parse'])) {
            return $conf['vodplayer'][$froms]['parse'];
        }
        if (isset($conf['vodplayer']['default']['parse'])) {
            return $conf['vodplayer']['default']['parse'];
        }
        if (!empty($conf['vodplayer']) && is_array($conf['vodplayer'])) {
            $first = reset($conf['vodplayer']);
            if (is_array($first) && isset($first['parse'])) {
                return $first['parse'];
            }
        }
        return '';
    }

    // scheme 规则已上收到 player_parse_kind()（前台与后台保存共用同一口径），此处不再各自实现一份
    }