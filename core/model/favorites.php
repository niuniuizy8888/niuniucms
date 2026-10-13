<?php
/**
 * @deprecated 兼容壳 — 全部函数转发到 \App\Service\Content\FavoriteService
 * 新代码禁止调用，应直接使用 FavoriteService 类。
 */
// hook model_favorites_start.php
!defined('DEBUG') and exit('Access Denied.');
/**
 * @deprecated 兼容壳：转发 FavoriteService::add($aid, $uid, 1)——新增文章收藏（model=1，写 aid 列；
 * 表带 uid_aid/uid_vid 双唯一键防跨类型撞键），并清该行 check 结果缓存。新代码直接调 FavoriteService。
 * @param int|string $aid 文章主键（底层 intval）
 * @param int|string $uid 会员 uid（底层 intval）
 * @return int|false 收藏行自增 id（语句失败=0）
 */
function art_favorites($aid, $uid)
{
    // hook model_favorites_art_favorites_start.php

    // hook model_favorites_art_favorites_end.php
    return \App\Service\Content\FavoriteService::add($aid, $uid, 1);
}
/**
 * @deprecated 兼容壳：转发 FavoriteService::remove($aid, $uid, 1)——按 uid+aid+model 条件删除收藏记录
 * 并清 check 缓存（与 art_favorites_del 为同一目标的两个历史别名）。
 * @param int|string $aid 文章主键
 * @param int|string $uid 会员 uid
 * @return int|false 受影响行数（未收藏过=0）
 */
/**
 * @deprecated 兼容壳：转发 FavoriteService::incrementCounter($aid, 1)——art 表 favorites 列原子 +1
 * 并失效 art_<aid> 行缓存。命名带 view 但语义是「收藏计数 +1」，非浏览。无返回值。
 * @param int|string $aid 文章主键
 * @return void
 */
/**
 * @deprecated 兼容壳：转发 FavoriteService::check($aid, $uid, 1)——该会员是否收藏过此文章。
 * 结果带 300s 缓存（键 fav_check_uid_aid_model）；$uid 为空（未登录）恒 false（fail-closed）。
 * @param int|string $aid 文章主键
 * @param int|string $uid 会员 uid
 * @return bool 已收藏 TRUE
 */
/**
 * @deprecated 兼容壳：转发 FavoriteService::remove($aid, $uid, 1)——删除文章收藏记录，
 * 与 art_unfavorites 完全同实现（历史别名并存，勿再新增第三种叫法）。
 * @param int|string $aid 文章主键
 * @param int|string $uid 会员 uid
 * @return int|false 受影响行数（未收藏过=0）
 */
/**
 * @deprecated 兼容壳：转发 FavoriteService::decrementCounter($aid, 1)——取消收藏时 art.favorites 原子 −1，
 * 条件带 favorites>0 钳制（F-10 防并发负漂移）并失效 art_<aid> 行缓存。无返回值。
 * @param int|string $aid 文章主键
 * @return void
 */
/**
 * @deprecated 兼容壳：转发 FavoriteService::incrementCounter($aid, 0)——视频收藏计数 +1（model=0，
 * vod 表 favorites 原子 +1 并失效 vod_<id> 行缓存）。⚠ 形参名沿袭 $aid，实际语义是 vid；无返回值。
 * @param int|string $aid 视频 vid（形参名为历史遗留）
 * @return void
 */
/**
 * @deprecated 兼容壳：转发 FavoriteService::check($aid, $uid, 0)——是否收藏过该视频（model=0 走 vid 列）。
 * ⚠ 形参名沿袭 $aid，实际语义是 vid。结果带 300s 缓存；未登录恒 false（fail-closed）。
 * @param int|string $aid 视频 vid（形参名为历史遗留）
 * @param int|string $uid 会员 uid
 * @return bool
 */
/**
 * @deprecated 兼容壳：转发 FavoriteService::remove($aid, $uid, 0)——删除视频收藏记录，
 * 与 vod_unfavorites 完全同实现（历史别名并存）。⚠ 形参名沿袭 $aid，实际语义是 vid。
 * @param int|string $aid 视频 vid（形参名为历史遗留）
 * @param int|string $uid 会员 uid
 * @return int|false 受影响行数
 */
/**
 * @deprecated 兼容壳：转发 FavoriteService::decrementCounter($aid, 0)——取消视频收藏，vod.favorites
 * 原子 −1（带 favorites>0 钳制，F-10）并失效 vod_<id> 行缓存。⚠ 形参名沿袭 $aid，实际语义是 vid；无返回值。
 * @param int|string $aid 视频 vid（形参名为历史遗留）
 * @return void
 */
/**
 * @deprecated 兼容壳：转发 FavoriteService::add($aid, $uid, 0)——新增视频收藏（model=0，写 vid 列）。
 * ⚠ 形参名沿袭 $aid，实际语义是 vid。新代码直接调 FavoriteService。
 * @param int|string $aid 视频 vid（形参名为历史遗留）
 * @param int|string $uid 会员 uid
 * @return int|false 收藏行自增 id
 */
function vod_favorites($aid, $uid)
{
    // hook model_favorites_vod_favorites_start.php

    // hook model_favorites_vod_favorites_end.php
    return \App\Service\Content\FavoriteService::add($aid, $uid, 0);
}
