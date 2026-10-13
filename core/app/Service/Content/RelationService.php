<?php
namespace App\Service\Content;

class RelationService
{
    /**
     * 视频详情/播放页「相关推荐」装配（调用点 VodService/PlayService）——带片段缓存（R7-P10）：
     * 缓存键含 vid + tag 字段指纹（后台改标签自动换键失效），命中直接返回，TTL 600s。
     * tag 为空时惰性补种：用 地区/语言/年份/分类名 走 TagFlowService::post 生成 JSON 并回写
     * vod.tag（VideoService::update，**引用形参同步 $video['tag']/tag_fmt**）；
     * 关联查询 TagService::videoFind 查空时降级 tag_video__find 全量兜底（100 条）。
     * @param array $video 视频行（引用传入；可能被就地补 tag/tag_fmt）
     * @param int $vid 视频 id（调用点 intval 后传入）
     * @param int $cid 所属分类 id（TagFlowService::post 落 tag_video 用）
     * @param int $limit 相关推荐条数上限（默认 12）
     * @return array<int, array> 推荐视频列表（空推荐=空数组；不会返回 NULL）
     */
    public static function ensureVideoTagsAndRelated(array &$video, $vid, $cid, $limit = 12)
    {
        // R7-P10：详情/播放页「相关推荐」片段缓存（用户无关，随 vid 稳定）。
        // 命中即跳过 TagService::videoFind + findAsc 多条查询；TTL 600s。键含 tag 指纹，
        // 后台改标签后自动换键失效（无需在共享 ContentQueryTrait::update 里加删除、避免影响 Art 侧断言）。
        $relKey = 'video_related_' . intval($vid) . '_' . md5((string)($video['tag'] ?? ''));
        $cachedRelated = cache_get($relKey);
        if (is_array($cachedRelated)) return $cachedRelated;
        if (empty($video['tag'])) {
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
            $arrlist = TagService::videoFind($tagids, 1, 100);
            !$arrlist and $arrlist = tag_video__find(array(), array('id' => 1), 1, 100);
            if ($arrlist) {
                $count = count($arrlist);
                $total = $count > $limit ? $limit : $count;
                $tidarr = array_keys(array_slice($arrlist, 0, $total, true));
                $likelist = VideoService::findAsc($tidarr, $total, true);
            }
        }

        $likelist = $likelist ?: array();
        if (!empty($likelist)) cache_set($relKey, $likelist, 600);
        return $likelist;
    }

    /**
     * 文章详情页「相关推荐」装配（调用点 Front/ArtService）。
     * 现存疑点（仅记录不改动）：$tagids 来自文章 tag_fmt，但查询走 TagService::videoFind
     * （tag_video 表，返回**视频 vid 键**），再把这些 id 喂给 ArtService::findAsc——
     * 关联表与回表不同域，疑似复制视频侧逻辑所致，正确性存疑（详见批次汇报）。
     * 其余同 ensureVideoTagsAndRelated 的降级/随机取数形态（本函数无缓存、不改引用参数）。
     * @param array $art 文章行（需含 tag/tag_fmt，tag_fmt 以 tagid 为键）
     * @param int $limit 推荐条数上限（默认 12）
     * @return array 推荐文章列表（ArtService::findAsc 产物；无 tag/查空时为空数组）
     */
    public static function buildArtRelated(array $art, $limit = 12)
    {
        $likelist = array();
        if (!empty($art['tag'])) {
            $tagids = is_array($art['tag_fmt']) ? array_keys($art['tag_fmt']) : array();
            $arrlist = TagService::videoFind($tagids, 1, 100);
            !$arrlist and $arrlist = tag_video__find(array(), array('id' => 1), 1, 100);
            if ($arrlist) {
                $count = count($arrlist);
                $total = $count > $limit ? $limit : $count;
                $tidarr = $count > 20 ? array_rand($arrlist, $total) : array_keys($arrlist);
                $likelist = ArtService::findAsc($tidarr, $total);
            }
        }
        return $likelist ?: array();
    }

    /**
     * 标签页混合内容列表（调用点 Front/TagService）：先按 tagid 取关联视频分页
     * （TagService::videoFind，**查空直接返回 NULL——即使该标签下只有文章也会被整体判空**），
     * 再 videolist(light 查询) + ArtService::findByKeyword(标签名) 简单 merge。
     * @param int $tagid 标签 id
     * @param string $tagName 标签名（文章侧按关键词匹配用）
     * @param int $page 页码
     * @param int $pagesize 每页条数
     * @return array|null 视频行与文章行的合并列表；标签无视频关联时 NULL
     */
    public static function buildTagMixedList($tagid, $tagName, $page, $pagesize)
    {
        $arr = TagService::videoFind($tagid, $page, $pagesize);
        if (empty($arr)) {
            return null;
        }

        $vidarr = arrlist_values($arr, 'vid');
        $videolist = VideoService::find($vidarr, $pagesize, true, true);
        $artlist = ArtService::findByKeyword($tagName);
        $videolist = is_array($videolist) ? $videolist : array();
        $artlist = is_array($artlist) ? $artlist : array();
        return array_merge($videolist, $artlist);
    }
}
