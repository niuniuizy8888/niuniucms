<?php
namespace App\Service\Content;

class TagFlowService
{
    /**
     * 内容保存前的标签串预处理：逗号切分→剥空项→去重，交 process() 落 tag/tag_video 表。
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
     * 编辑内容时的标签差量更新：$oldtag 空退化为 post()；否则把旧 JSON（tagid=>name）与新标签名逐一对账
     * ——同名沿用原 tagid 进 $tagarr，新增的进 $create_tag，旧而新未留下的 tagid 走 oldTagDelete()
     * （连带解绑本 vid 并按需删标签、count 递减）。新名逐个过 filter_all_html 入库级转义。
     * @param int $vid 内容 ID
     * @param int $cid 分类 ID
     * @param string $newtag 逗号分隔的新标签名串
     * @param string|array $oldtag 旧标签 JSON（tagid=>name 序列化；非数组时 xn_json_decode，解失败按空集）
     * @return string 更新后 tagid=>name 的 JSON 串或空串
     */
    /**
     * 删除旧标签在**本内容**上的绑定，并回补标签计数。
     * ⚠ 修正一（解绑范围）：原实现取 videoFindByVid($vid,...) 后把结果里的绑定行**无条件全删**，
     *   而该查询是按 vid 查的、不带 tagid 条件 ⇒ 本应只解绑"本次被移除的标签"，实际会把该内容
     *   **保留标签**的绑定一起删掉（此前之所以没暴露，是因为旧的 vid 键折叠让每次只返回 1 行）。
     *   现按传入的 $tagids 过滤，只删目标标签的绑定行。
     * ⚠ 修正二（计数回补）：原实现恒 `count-1`，而**存量库**（未跑去重迁移）的 tag_id 没有
     *   (tagid,vid) 唯一索引、历史 REPLACE 等同 INSERT ⇒ 同一绑定可存在多行；多行只减 1 会让 tag.count 长期虚高，
     *   进而使"该标签是否还有内容"判断失真、标签永不被清理。现按**实际删掉的行数**逐标签减，
     *   减到 ≤0 的标签整行删除。回补仍走 tag_update()（会清 web_tag_{tagid} 缓存），
     *   按 delta 分组批量提交，避免绕过缓存失效。
     * @param array $tagids 待解绑的 tagid 列表
     * @param int $vid 内容 ID（解绑范围=仅此内容）
     * @return void
     */
    public static function oldTagDelete($tagids, $vid)
    {
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
        $delete_tagids = array();
        $dec_by_tag = array();
        foreach ($count_by_tag as $tid => $cnt) {
            // 查不到绑定行时保持原「减 1」口径（历史数据不一致，不能让标签永远清不掉）
            $n = isset($gone_by_tag[$tid]) ? $gone_by_tag[$tid] : 1;
            if ($cnt - $n <= 0) {
                $delete_tagids[] = $tid;
            } else {
                $dec_by_tag[$tid] = $n;
            }
        }
        // 单 id 逐个删：TagService::delete 按 id 成对清 web_tag_{tagid}/web_tag_{md5(name)} 缓存；
        //   不能把数组整体传给 tag_delete()——其内部 cache_delete('web_tag_' . $tagid) 会对数组做
        //   字符串拼接（PHP 8 告警 + 键变 web_tag_Array），被删标签的缓存不失效、幽灵标签存活 1800s。
        foreach ($delete_tagids as $_del_tid) {
            tag_delete($_del_tid);
        }
        self::decrementTagCounts($dec_by_tag);
    }

    /**
     * 标签计数回补（解绑之后调用）：**按实际删掉的绑定行数**减，而不是按"内容自带几个标签"恒减 1。
     * 为什么需要：**存量库**（未跑去重迁移）的 tag_id 没有 (tagid,vid) 唯一索引，且历史写入走
     * 不带主键的 REPLACE（等同 INSERT）⇒ 同一 (tagid,vid) 可能攒出多行绑定（新装库自带
     * UNIQUE uk_tagid_vid，不会出现）。绑定行全删掉了却只减 1 计数，
     * tag.count 就长期虚高，进而让「标签是否还有内容」的判定失真。
     * 实现口径：相同 delta 合并成一条 UPDATE；刻意走 tag_update() 而不是
     * Repository::bigUpdate()——后者不失效 web_tag_{tagid} 缓存，前台标签页/标签云会读到旧计数。
     * @param array $rowsByTag tagid => 本次实际删除的绑定行数（≤0 或 tagid≤0 的项跳过）
     * @param array $fallback  tagid => 期望减量；仅当该 tagid 在 $rowsByTag 里没有对应行时采用
     * @return void
     */
    public static function decrementTagCounts(array $rowsByTag, array $fallback = array())
    {
        $by_delta = array();
        foreach ($rowsByTag as $tid => $n) {
            $tid = intval($tid);
            $n = intval($n);
            if ($tid <= 0 || $n <= 0) continue;
            $by_delta[$n][] = $tid;
        }
        foreach ($fallback as $tid => $n) {
            $tid = intval($tid);
            if ($tid <= 0 || isset($rowsByTag[$tid])) continue;   // 已按实际行数减过，不重复减
            $n = intval($n) > 0 ? intval($n) : 1;
            $by_delta[$n][] = $tid;
        }
        // ⚠ 下限钳制（审查修复）：旧实现按行数硬减，数据漂移（count 已低于真实绑定行数）时会把
        //   count 减成负数。现先读受影响标签的当前计数，与 oldTagDelete 同口径：减到 ≤0 的标签
        //   整行清理（tag_delete 成对清缓存），其余按 delta 减——保证 count 恒 ≥0 且数据自洽。
        $tids = array();
        foreach ($by_delta as $ids) {
            foreach ($ids as $tid) $tids[] = $tid;
        }
        $counts = array();
        $rows = tag_find_by_tagids($tids, 1, count($tids));
        foreach ((array)$rows as $r) {
            $counts[intval($r['tagid'])] = intval($r['count']);
        }
        foreach ($by_delta as $n => $ids) {
            $dec = array();
            foreach ($ids as $tid) {
                $cur = $counts[$tid] ?? 0;
                if ($cur - $n <= 0) {
                    tag_delete($tid);   // 计数将归零/为负 ⇒ 清理整行（含缓存成对失效）
                } else {
                    $dec[] = $tid;
                }
            }
            !empty($dec) and tag_update($dec, array('count-' => $n));
        }
    }

    /**
     * 标签落库核心：$vid 空直接返回空串。新标签名经 trim/stripslashes/strip_tags/剥符号白名单化后
     * htmlspecialchars(ENT_QUOTES) 入库（模板层裸 echo，转义在入库侧完成）；同名复用现有 tagid，
     * 否则 tag_create（失败 message 终止）。tag_video 关联 bigInsert、tag.count 批量 +1。
     * 总额受 conf[tag_num] 限制：$tagarr（沿用旧标签）先占名额，新标签最多补 size-count($tagarr) 个。
     * @param int $vid 内容 ID
     * @param int $cid 分类 ID（当前实现体未使用，仅保留签名兼容）
     * @param array $new_tags 新标签名数组
     * @param array $tagarr 沿用的旧标签 tagid=>name（参与最终 JSON 与名额计算）
     * @return string tagid=>name 的 JSON 串；无标签时返回空串
     */
    public static function process($vid, $cid, $new_tags = array(), $tagarr = array())
    {
        $conf = _SERVER('conf');
        if (empty($vid)) return '';
        if ($new_tags) {
            $videoarr = array();
            $tagids = array();
            $i = 0;
            $size = $conf['tag_num'];
            $n = count($tagarr);
            $n = $n > $size ? $size : $size - $n;
            // ⚠ 插入前先取「本 vid 已绑定的 tagid 集合」（一次走 tag_id 的 vid 索引定点查询）。
            //   动机：tag_id 有 UNIQUE(tagid,vid)（见 core/install/install.sql），而 bigInsert 走的是
            //   **普通多值 INSERT** 会因整条语句 1062 失败 ⇒ 那一次保存的标签整批丢失
            //   （错误被 prepare_execute 吞成 rowCount=0，调用方看不见异常）。两层防护各管一件事：
            //   ① 本处预过滤 —— 只有真正新增的绑定才进 $tagids 做 count+1，避免"行没增加、计数增加"
            //      的漂移（这才是预过滤的目的，不是为了防炸批）；
            //   ② 写入侧 INSERT IGNORE（tag_video_big_insert 默认 TRUE）—— 兜住残余撞键
            //      （并发保存同一内容时两个请求都判定"未绑定"），只跳过那一行、不拖垮同批。
            //   两层不可只留一层：只留 IGNORE 计数会虚高，只留预过滤则竞态下整批丢。
            $bound = array();
            foreach (self::videoFindByVidAll($vid) as $r) {   // 分页取尽（旧实现 pagesize=1000 上限，存量重复绑定超限时预过滤漏判）
                $bound[intval($r['tagid'])] = true;
            }
            $pending = array();
            foreach ($new_tags as $name) {
                ++$i;
                $name = trim($name);
                $name = stripslashes($name);
                $name = strip_tags($name);
                $name = str_replace(array('&nbsp;', '#', "@", "$", "%", "^", '&', '·', '<', '>', '；', '`', '~', '!', '￥', '……', ';', '?', '？', '-', '—', '_', '=', '+', '.', '{', '}', '|', ':', '：', '、', '/', '。', '[', ']', '【', '】', '‘', '     ', '    ', '  ', '   ', '    '), '', $name);
                $name = htmlspecialchars($name, ENT_QUOTES);
                if ($name && $i <= $n) {
                    $read = tag_read_name($name);
                    $reused = (bool) $read;      // 复用已有标签：count 需要 +1；新建标签的 count 已在 tag_create 里给成 1
                    if (! $read) {
                        $arr = array('name' => $name, 'count' => 1);
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
                    // JSON 列无条件收：即便绑定行早已存在（数据漂移场景），本内容的 tag 映射也应包含它
                    $tagarr[$tid] = $read['name'];
                }
            }
            !empty($videoarr) and tag_video_big_insert($videoarr);
            !empty($tagids) and tag_update($tagids, array('count+' => 1));
        }
        return empty($tagarr) ? '' : xn_json_encode($tagarr);
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
        $key = 'tag_vid_' . intval($tagid) . '_' . intval($page) . '_' . intval($pagesize);
        static $cache = array();
        if (isset($cache[$key])) return $cache[$key];
        $r = cache_get($key);
        if (NULL === $r) {
            $r = tag_video__find(array('tagid' => $tagid), array('id' => -1), $page, $pagesize);
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
