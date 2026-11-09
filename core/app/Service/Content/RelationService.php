<?php
namespace App\Service\Content;

class RelationService
{
    /**
     * 视频详情/播放页「相关推荐」装配（调用点 VodService/PlayService）——带片段缓存（R7-P10）：
     * 缓存键含视频、标签与可读分类指纹；命中后仍复核权限，TTL 600s。
     * tag 为空时惰性补种：用 地区/语言/年份/分类名 走 TagFlowService::post 生成 JSON 并回写
     * vod.tag（VideoService::update，**引用形参同步 $video['tag']/tag_fmt**）；
     * 关联查询先筛选可读分类，再取推荐条数。
     * @param array $video 视频行（引用传入；可能被就地补 tag/tag_fmt）
     * @param int $vid 视频 id（调用点 intval 后传入）
     * @param int $cid 所属分类 id（TagFlowService::post 落 tag_video 用）
     * @param int $limit 相关推荐条数上限（默认 12）
     * @return array<int, array> 推荐视频列表（空推荐=空数组；不会返回 NULL）
     */
    public static function ensureVideoTagsAndRelated(array &$video, $vid, $cid, $limit = 12)
    {
        $relKey = 'video_related_v3_' . intval($vid) . '_' . md5((string)($video['tag'] ?? ''))
            . '_' . (function_exists('page_cache_version') ? page_cache_version() : '0')
            . '_' . ContentVisibilityService::fingerprint() . '_' . max(1, min(100, (int)$limit));
        $cachedRelated = cache_get($relKey);
        if (is_array($cachedRelated)) return ContentVisibilityService::filter($cachedRelated);
        if (empty($video['tag']) && \App\Service\System\ArticleTagSchema::ready()) {
            $tags = $video['area'] . ',' . $video['lang'] . ',' . $video['year'] . ',' . $video['cate_name'];
            $tag_json = \App\Service\Content\TagFlowService::post($vid, $cid, $tags);
            if (xn_strlen($tag_json) >= 120) {
                $s = xn_substr($tag_json, -1, NULL);
                if ('}' != $s) {
                    $len = mb_strripos($tag_json, ',', 0, 'UTF-8');
                    $tag_json = $len ? xn_substr($tag_json, 0, $len) . '}' : '';
                }
            }
            if (!empty($tag_json)) {
                VideoService::update($vid, array('tag' => $tag_json));
                $video['tag'] = $tag_json;
                $video['tag_fmt'] = xn_json_decode($tag_json);
                !is_array($video['tag_fmt']) and $video['tag_fmt'] = array();
            }
        }

        $likelist = array();
        if (!empty($video['tag']) && !empty($video['tag_fmt']) && is_array($video['tag_fmt'])) {
            $tagids = array_keys($video['tag_fmt']);
            $likelist = ArticleTagService::relatedVideos($tagids, (int)$limit);
        }

        $likelist = $likelist ?: array();
        if (!empty($likelist)) cache_set($relKey, $likelist, 600);
        return $likelist;
    }

    /**
     * 文章详情页「相关推荐」装配（调用点 Front/ArtService）。
     * 只从文章专用标签关系取 aid，不再把视频 vid 当作文章 ID。
     * @param array $art 文章行（需含 tag/tag_fmt，tag_fmt 以 tagid 为键）
     * @param int $limit 推荐条数上限（默认 12）
     * @return array 可读的推荐文章列表；无标签或查空时为空数组
     */
    public static function buildArtRelated(array $art, $limit = 12)
    {
        $tags = ArticleTagService::map((string)($art['tag'] ?? ''));
        return $tags ? ArticleTagService::find(array_keys($tags), 1, $limit, (int)($art['aid'] ?? 0)) : array();
    }

    /**
     * 标签页先对视频/文章关联联合分页，再分别回表；总数与两类真实绑定计数一致。
     * @param int $tagid 标签 id
     * @param string $tagName 保留兼容参数，实际按标签 ID 查询
     * @param int $page 页码
     * @param int $pagesize 每页条数
     * @return array 可读视频与文章的分页列表
     */
    public static function buildTagMixedList($tagid, $tagName, $page, $pagesize)
    {
        return ArticleTagService::mixedList((int)$tagid, (int)$page, (int)$pagesize);
    }
}
