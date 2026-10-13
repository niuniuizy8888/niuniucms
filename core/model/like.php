<?php
/**
 * @deprecated 兼容壳 — 全部函数转发到 \App\Service\Content\LikeService
 * 新代码禁止调用，应直接使用 LikeService 类。
 */
// hook model_like_start.php
!defined('DEBUG') and exit('Access Denied.');
/** 转发 LikeService::add($aid, $uid, 1)——文章点赞记录新增（不自动加 art.likes 计数，配套用 art_like_view）。
 *  @param int $aid 文章 ID @param int $uid 用户 ID
 *  @return int|FALSE 自增 id；并发撞 uid_aid 唯一键时为底层失败值 */
function art_like($aid, $uid)
{
    // hook model_like_art_like_start.php

    // hook model_like_art_like_end.php
    return \App\Service\Content\LikeService::add($aid, $uid, 1);
}
/** 转发 LikeService::remove($aid, $uid, 1)——删文章点赞记录；不减 art.likes、不做存在性预检（取消链路需配 art_unlike_view）。
 *  @param int $aid 文章 ID @param int $uid 用户 ID
 *  @return int|FALSE 影响行数（无记录=0） */
/** 转发 LikeService::incrementCounter($aid, 1)——art.likes +1 并清 'art_{aid}' 行缓存。无返回值。
 *  @param int $aid 文章 ID */
/** 转发 LikeService::check($aid, $uid, 1)——文章「是否已赞」：缓存键 like_check_{uid}_{aid}_1（300s，缓存值存 1/0），
 *  miss 回表 COUNT>0；游客（uid 空）直接 false 不查库；add/remove 两侧主动清键收敛窗口内滞后。
 *  @param int $aid 文章 ID @param int $uid 用户 ID
 *  @return bool */
/** 转发 LikeService::remove($aid, $uid, 1)——与 art_unlike 同转发（历史两名并存），删文章点赞记录。
 *  @param int $aid 文章 ID @param int $uid 用户 ID
 *  @return int|FALSE 影响行数（无记录=0） */
/** 转发 LikeService::decrementCounter($aid, 1)——art.likes -1（带 likes>0 钳制防负漂移，审计 F-10）并清 'art_{aid}' 行缓存。无返回值。
 *  @param int $aid 文章 ID */
/** 转发 LikeService::incrementCounter($aid, 0)——vod.likes +1 并清 'vod_{vid}' 行缓存。形参名 $aid 实为 vid。无返回值。
 *  @param int $aid 视频 ID（vid） */
/** 转发 LikeService::check($aid, $uid, 0)——视频「是否已赞」，缓存键 like_check_{uid}_{vid}_0（300s）；游客直接 false。
 *  @param int $aid 视频 ID（vid） @param int $uid 用户 ID
 *  @return bool */
/** 转发 LikeService::remove($aid, $uid, 0)——删视频点赞记录；不减 vod.likes、不做存在性预检。形参名 $aid 实为 vid。
 *  @param int $aid 视频 ID（vid） @param int $uid 用户 ID
 *  @return int|FALSE 影响行数（无记录=0） */
/** 转发 LikeService::decrementCounter($aid, 0)——vod.likes -1（likes>0 钳制）并清 'vod_{vid}' 行缓存。形参名 $aid 实为 vid。无返回值。
 *  @param int $aid 视频 ID（vid） */
/** 转发 LikeService::add($aid, $uid, 0)——视频点赞记录新增（写 vid 列；不自动加 vod.likes，配套用 vod_like_view）。形参名 $aid 实为 vid。
 *  @param int $aid 视频 ID（vid） @param int $uid 用户 ID
 *  @return int|FALSE 自增 id；并发撞 uid_vid 唯一键时为底层失败值 */
function vod_like($aid, $uid)
{
    // hook model_like_vod_like_start.php

    // hook model_like_vod_like_end.php
    return \App\Service\Content\LikeService::add($aid, $uid, 0);
}
