<?php
namespace App\Service\Content\Traits;

/**
 * 内容列表访问控制过滤（主线 2：Art/Video 同构收敛 + 修复下沉轮遗漏）
 *
 * 背景：video_list_access_filter 兼容壳（core/model/vod.php 的 video_list_access_filter()）转发到
 * VideoService::listAccessFilter，但该方法在 08-13 下沉轮中未随壳迁移——视频
 * 列表统一拉取链（VideoQueryTrait::unifiedPull → video_list_access_filter）实际
 * 会触发未定义方法 fatal。现由本 Trait 提供单一实现，ArtService/VideoService 共用。
 */
trait ListAccessFilterTrait
{
    /**
     * 按分类访问权限过滤内容列表（accesson 开启的分类做 allowread 校验）
     * @param array $list 引用传入的列表（cid 为键的条目集合）
     * @param int $gid 用户组 ID
     */
    public static function listAccessFilter(&$list, $gid)
    {
        global $catelist;
        if (empty($list)) return NULL;
        foreach ($list as $id => $item) {
            if (empty($catelist[$item['cid']]['accesson'])) continue;
            if (!cate_access_user($item['cid'], $gid, 'allowread')) unset($list[$id]);
        }
    }
}
