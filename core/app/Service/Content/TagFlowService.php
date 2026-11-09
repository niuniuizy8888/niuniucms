<?php
namespace App\Service\Content;

class TagFlowService
{
    /**
     * 视频保存前的标签串预处理：逗号切分→剥空项→去重，交 process() 落 tag/tag_video 表。
     * $str 空或切分后全空时直接返回空串（不碰库、也不清空旧关联——调用方拿 '' 覆盖原 tag 字段）。
     * @param int $vid 内容 ID
     * @param int $cid 分类 ID（当前实现体未使用，仅保留签名兼容）
     * @param string $str 逗号分隔的标签名原文
     * @return string 最终 tagid=>name 的 JSON 串，或空串
     */
    public static function post($vid, $cid, $str)
    {
        if (empty($str)) return '';
        $arr = explode(',', $str);
        $arr = array_filter($arr);
        if (empty($arr)) return '';
        $arr = array_unique($arr);
        return self::process($vid, $cid, $arr);
    }

    /**
     * 只移除本视频指定标签的绑定，再按视频/文章两张索引重算受影响标签的总数。
     * 不删除共享字典项，避免移除视频标签时破坏文章或回收站中的标签。
     * @param array $tagids 待解绑的标签 ID
     * @param int $vid 视频 ID
     */
    public static function oldTagDelete($tagids, $vid)
    {
        \App\Service\System\ArticleTagSchema::ensureReady();
        $tagids = array_values(array_filter(array_map('intval', (array)$tagids)));
        if (empty($tagids) || empty($vid)) return;
        $arrlist = tag_find_by_tagids($tagids, 1, count($tagids));
        $count_by_tag = array();
        if (is_array($arrlist)) {
            foreach ($arrlist as $val) {
                $count_by_tag[intval($val['tagid'])] = intval($val['count']);
            }
        }
        // 取该内容的全部绑定行（videoFindByVidAll 分页取尽——旧实现 pagesize 启发式上限
        //   在存量重复绑定超限时会清理不净、残留孤儿行）
        $arlist = self::videoFindByVidAll($vid);
        $del_ids = array();
        $gone_by_tag = array();
        if (is_array($arlist)) {
            foreach ($arlist as $val) {
                $tid = intval($val['tagid']);
                if (!in_array($tid, $tagids, TRUE)) continue;   // 关键：只解绑本次移除的标签
                $del_ids[] = intval($val['id']);
                isset($gone_by_tag[$tid]) or $gone_by_tag[$tid] = 0;
                ++$gone_by_tag[$tid];
            }
        }
        if (!empty($del_ids)) {
            \App\Repository\RepositoryFactory::tagVideo()->delete(array('id' => $del_ids));
        }
        ArticleTagService::recount($tagids);
    }

    /**
     * 解绑完成后的兼容入口：忽略旧减量，按两张模型索引中的真实内容数重算。
     * 历史重复绑定按视频 ID 去重；没有活跃内容的标签保留 count=0，供回收恢复复用。
     * @param array $rowsByTag 本次实际删掉的绑定对应标签集合
     * @param array $fallback 内容快照中的标签集合
     */
    public static function decrementTagCounts(array $rowsByTag, array $fallback = array())
    {
        \App\Service\System\ArticleTagSchema::ensureReady();
        ArticleTagService::recount(array_merge(array_keys($rowsByTag), array_keys($fallback)));
    }

    /**
     * 视频标签写入：规范化名称、复用字典、新增 tag_id 绑定并重算受影响标签总数。
     * 文章采集必须走 ArticleTagService::post，不可把 aid 当作 vid 传入。
     * @param int $vid 视频 ID
     * @param int $cid 保留兼容参数
     * @param array $new_tags 新标签名数组
     * @param array $tagarr 沿用的旧标签映射
     * @return string tagid=>name JSON；没有标签时为空串
     */
    public static function process($vid, $cid, $new_tags = array(), $tagarr = array())
    {
        \App\Service\System\ArticleTagSchema::ensureReady();
        $conf = _SERVER('conf');
        if (empty($vid)) return '';
        if ($new_tags) {
            $videoarr = array();
            $tagids = array();
            $i = 0;
            $size = $conf['tag_num'];
            $n = count($tagarr);
            $n = $n > $size ? $size : $size - $n;
            // 先取当前视频的绑定集合；INSERT IGNORE 再兜住并发撞键。
            // 插入后按两张模型索引重算，不依赖「准备插入多少行」猜测计数。
            $bound = array();
            foreach (self::videoFindByVidAll($vid) as $r) {   // 分页取尽（旧实现 pagesize=1000 上限，存量重复绑定超限时预过滤漏判）
                $bound[intval($r['tagid'])] = true;
            }
            $pending = array();
            foreach ($new_tags as $name) {
                ++$i;
                $name = self::normalizeName($name);
                if ($name && $i <= $n) {
                    $read = tag_read_name($name);
                    $reused = (bool) $read;
                    if (! $read) {
                        $arr = array('name' => $name, 'count' => 0);
                        $tagid = tag_create($arr);
                        FALSE === $tagid and message(-1, lang('create_failed'));
                        $read = array('tagid' => $tagid, 'name' => $name);
                    }
                    $tid = intval($read['tagid']);
                    if (! isset($bound[$tid]) && ! isset($pending[$tid])) {
                        $pending[$tid] = true;
                        $videoarr[] = array('tagid' => $tid, 'vid' => $vid);
                        if ($reused) $tagids[] = $tid;
                    }
                    // JSON 列无条件收：即便绑定行早已存在，本视频的 tag 映射也应包含它。
                    $tagarr[$tid] = $read['name'];
                }
            }
            !empty($videoarr) and tag_video_big_insert($videoarr);
            ArticleTagService::recount(array_keys($tagarr));
        }
        return empty($tagarr) ? '' : xn_json_encode($tagarr);
    }

    public static function normalizeName(string $name): string
    {
        $name = strip_tags(stripslashes(trim($name)));
        $name = str_replace(array('&nbsp;', '#', '@', '$', '%', '^', '&', '·', '<', '>', '；', '`', '~', '!', '￥', '……', ';', '?', '？', '-', '—', '_', '=', '+', '.', '{', '}', '|', ':', '：', '、', '/', '。', '[', ']', '【', '】', '‘', '     ', '    ', '  ', '   ', '    '), '', $name);
        return htmlspecialchars($name, ENT_QUOTES);
    }

    /**
     * 按关联表主键 id 更新 tag_video 绑定行 —— $id 空返回 FALSE，否则转兼容壳 tag_video__update()
     * （→ RepositoryFactory::tagVideo()->update，update 数组空也返回 FALSE）。
     * @param int $id tag_video 关联行主键
     * @param array $update 更新字段
     * @return int|FALSE 影响行数
     */
    /**
     * 按标签查绑定视频列表（id 倒序）—— 兼容壳 tag_video__find() 上叠双层缓存
     * （进程内 static + 分布式 600s，键含 tagid/page/pagesize）。
     * @param int $tagid 标签 ID
     * @param int $page 页码
     * @param int $pagesize 每页条数
     * @return array|NULL 关联行数组（以 vid 为键）；查空归一为 NULL（非空数组判定）
     */
    public static function videoFind($tagid, $page, $pagesize)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$tagid))));
        sort($ids);
        if (!$ids) return NULL;
        $key = 'tag_vid_v2_' . md5(implode(',', $ids)) . '_' . intval($page) . '_' . intval($pagesize)
            . '_' . (function_exists('page_cache_version') ? page_cache_version() : '0') . '_' . ArticleTagService::revision();
        static $cache = array();
        if (isset($cache[$key])) return $cache[$key];
        $r = cache_get($key);
        if (NULL === $r) {
            $r = tag_video__find(array('tagid' => $ids), array('id' => -1), $page, $pagesize);
            if ($r && !\App\Service\System\ArticleTagSchema::ready()) {
                // Before the backend migration, ambiguous legacy article bindings must not select a video.
                $videos = \App\Repository\RepositoryFactory::vod()->find(array('vid' => array_column($r, 'vid')), array(), 1, count($r), 'vid', array('vid', 'tag'));
                foreach ($r as $rowKey => $row) {
                    $tags = ArticleTagService::map((string)($videos[$row['vid']]['tag'] ?? ''));
                    if (!isset($tags[(int)$row['tagid']])) unset($r[$rowKey]);
                }
            }
            $r and cache_set($key, $r, 600);
        }
        $cache[$key] = $r ? $r : NULL;
        return $cache[$key];
    }

    /**
     * 按内容 vid 查其标签绑定行 —— 转 tag_video__find()，$vid 支持单值或数组（走 IN）。
     * ⚠ 修正一（解绑不彻底 → 关联行累积）：兼容壳 tag_video__find 的默认 $key='vid'，会把同一 vid 的
     *   **多条**绑定折叠成 1 行；而删除内容/换标签的链路都靠本方法取「待删 id 列表」，于是每个 vid
     *   只删掉 1 条绑定，其余成孤儿行，tag.count 也只减 1（计数与实况双双失真）。
     *   现显式传 $key='' ⇒ 返回顺序数组＝全部绑定行。
     * ⚠ 修正二（缓存反噬正确性）：原实现带进程 static + 600s 分布式缓存，但本方法的结果是被**写路径**
     *   消费的（先查后删/先查后插），而缓存键含 pagesize 与 id 列表哈希、绑定变更后无法精确失效
     *   ⇒ 同一内容的连续两次保存会照旧缓存做差量判断。故去掉两层缓存：代价是每次保存多一条
     *   走 tag_id.KEY(vid) 索引的查询，换回删除口径的正确性。
     * 与 videoFind() 的区别保留：查空原样返回空数组，不折叠成 NULL。
     * @param int|array $vid 内容 ID 或 ID 数组
     * @param int $page 页码
     * @param int $pagesize 每页条数（调用方要给足：一个内容可有多个标签，存量库还可能有重复绑定）
     * @return array 绑定行顺序数组（每行含 id/tagid/vid）；查空为空数组
     */
    public static function videoFindByVid($vid, $page, $pagesize, $orderby = array())
    {
        // ⚠ 默认按 id 升序（复核修正）：分页取尽（videoFindByVidAll）在**无 ORDER BY** 时 LIMIT/OFFSET
        //   的跨页顺序由存储引擎决定，批量删除 >500 行绑定（500 vid × 多标签很常见）时可能重复/漏行，
        //   表现为「解绑不彻底 + tag.count 漂移」。
        return tag_video__find(array('vid' => $vid), $orderby ? $orderby : array('id' => 1), $page, $pagesize, '');
    }

    /**
     * 取 vid（单值或数组）的**全部**绑定行：按 500/页分页循环取尽。
     * 供删除/解绑/预过滤等「必须看全量」的写路径使用——消除调用方 pagesize 启发式上限
     * （存量库重复绑定超过上限时会清理不净、残留孤儿行、计数漂移）。
     * @param int|array $vid 内容 ID 或 ID 数组
     * @return array 绑定行顺序数组（每行含 id/tagid/vid）；查空为空数组
     */
    public static function videoFindByVidAll($vid)
    {
        $all = array();
        $page = 1;
        $pagesize = 500;
        do {
            $rows = self::videoFindByVid($vid, $page, $pagesize);
            foreach ($rows as $r) $all[] = $r;
            $page++;
        } while (count($rows) >= $pagesize);
        return $all;
    }
}
