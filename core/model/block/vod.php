<?php
// hook model_block_vod_start.php

// ──────────────────────────────────────────────
// A 类：扁平视频列表
// ──────────────────────────────────────────────

/**
 * 模板区块 {block:vod_index_new}：首页主列表的前 N 条 —— **不查库**，直接切 global $videolist
 * 的前段（$videolist 由引导层/前台路由预载），属于「同页复用」型区块。
 * 结果走 block_cache（请求内 static + MultiLayerCache 防击穿回源，TTL 3600s），
 * 缓存键 = block_key('vod_index_new', n)，键里只带条数与 url_rewrite_on ⇒
 * **同一份切片会被跨页复用**：不同栏目页用同一条码取到的是各自当页 $videolist 的首入结果
 * （谁先落缓存谁定内容），需要按栏目区分请改用带 cid 的 block_vod_list_new。
 * ⚠ $arr['n'] 未给时 $pagesize 是空串，array_slice 的长度参数在 PHP 8 下不是合法 int ⇒
 *   模板调用方必须传 n（沿用原注释里对同类块位的警告口径）。
 * @param array $arr 模板块参数：n=条数（必填）
 * @return mixed 视频行数组（$videolist 的切片）；缓存层异常等情况下可能是 block_cache 的 $empty 缺省 null
 */
function block_vod_index_new($arr)
{
    // hook model_block_vod_block_vod_index_new_start.php


    global $videolist;
    $pagesize = empty($arr['n']) ? '' : $arr['n'];
    $key = block_key('vod_index_new', $pagesize);
    // hook model_block_vod_block_vod_index_new_end.php
    return block_cache($key, 3600, function () use ($videolist, $pagesize) {
        return array_slice($videolist, 0, $pagesize);
    });
}

/**
 * 模板区块 {block:vod_list_new}：当前列表页主列表的前 N 条（不再查库，直接切 global $videolist 前段）。
 * cid 取自 $_SESSION['cid']（当前栏目上下文）参与键生成；结果走 block_cache（进程内 + MultiLayerCache 防击穿回源，TTL 3600s）。
 * ⚠ $arr['n'] 未给时 $pagesize 为空串，array_slice 长度参数在 PHP8 下非合法 int——模板调用方必须传 n。
 * @param array $arr 模板块参数：n=条数
 * @return mixed 视频行数组（$videolist 的 vid 键切片）；缓存层异常时可能为 null（block_cache 的 $empty 缺省）
 */
function block_vod_list_new($arr)
{
    // hook model_block_vod_block_vod_list_new_start.php


    global $videolist;
    $cid = $_SESSION['cid'];
    $pagesize = empty($arr['n']) ? '' : $arr['n'];
    $key = block_key('vod_list_new', $cid, $pagesize);
    // hook model_block_vod_block_vod_list_new_end.php
    return block_cache($key, 3600, function () use ($videolist, $pagesize) {
        return array_slice($videolist, 0, $pagesize);
    });
}

// ──────────────────────────────────────────────
// B 类：带排名视频列表
// ──────────────────────────────────────────────

/**
 * 模板区块 {block:vod_top}（B 类：带排名视频列表）：按累计 views 降序的热门位，
 * 转发 VodBlockService::top($cid, $n) —— 显式轻量列（审计 R10：排除 play_url 等大字段）、
 * 每行补排名序号 n，**不产 play_data** ⇒ 只适合做榜单展示，详情页数据要另取。
 * cid 传空即全站口径；缓存键 block_key('vod_top', 'cidX', 'nY')，TTL 3600s，
 * 且 block_cache 的 $empty 显式给了**空数组**（服务层无数据也返回空数组），
 * 所以本块位恒为数组，模板可直接 loop 而不必判 NULL。
 * ⚠ 返回以 vid 为键（排名顺序即数组顺序）⇒ 键不连续，不要按下标取值，遍历时用 foreach。
 * @param array $arr 模板块参数：cid=分类过滤（缺省 = 全站）、n=条数（缺省 20，内部 intval）
 * @return array vid 键的视频轻量行数组（每行补 n 序号）；无数据 = 空数组
 */
function block_vod_top($arr)
{
    // hook model_block_vod_block_vod_top_start.php


    $cid = empty($arr['cid']) ? array() : array('cid' => $arr['cid']);
    $n   = empty($arr['n']) ? 20 : intval($arr['n']);
    $key = block_key('vod_top', 'cid' . ($arr['cid'] ?? ''), 'n' . $n);
    // hook model_block_vod_block_vod_top_end.php
    return block_cache($key, 3600, function () use ($cid, $n) {
        return \App\Service\Block\VodBlockService::top(array_value($cid, 'cid'), $n);
    }, array());
}

// ──────────────────────────────────────────────
// A 类：随机视频
// ──────────────────────────────────────────────

/**
 * 模板区块 {block:vod_rand}（A 类：随机视频）：转发 VodBlockService::random($cid, $n)。
 * 随机**只在候选窗口内**做：服务层先按 vid 降序取 max(n*5, 100) 条 id、洗牌后截前 N（**上限 20**），
 * 再 findAsc 轻量装配并按 global $gid 做访问过滤（与主列表同口径）——
 * 用「不 ORDER BY RAND()」换性能，代价是分类越大、长尾 vid 越难被抽中。
 * ⚠ 结果进 block_cache 3600s：**随机一小时固定**，刷新换图靠缓存过期，不是每次请求重洗；
 *   键里带的是 cid 的拼接串（`_cid_` 形态）与条数。
 * ⚠ 候选窗口为空时服务层返回 **NULL**（本块位没给 block_cache 的 $empty 兜底）⇒
 *   模板 loop 前需按 falsy 处理，别假设一定是数组。
 * @param array $arr 模板块参数：cid=分类过滤（缺省 '' = 全站窗口）、n=条数（缺省 20，服务层封顶 20）
 * @return array|NULL 视频条目数组；窗口为空 = NULL；block_cache 3600s
 */
function block_vod_rand($arr)
{
    // hook model_block_vod_block_vod_rand_start.php


    $cid  = empty($arr['cid']) ? '' : $arr['cid'];
    $cids = $cid ? '_' . $cid . '_' : '';
    $n    = empty($arr['n']) ? 20 : intval($arr['n']);
    $key  = block_key('vod_rand', $cids, 'n' . $n);
    // hook model_block_vod_block_vod_rand_end.php
    return block_cache($key, 3600, function () use ($cid, $n) {
        return \App\Service\Block\VodBlockService::random($cid, $n);
    });
}

/**
 * 模板区块 {block:vod_list_rand}：当前栏目（$_SESSION['cid']）随机推荐位。
 * 转发 VodBlockService::random($cid, n)——候选窗口 max(n*5,100) 内洗牌截前 N（上限 20），findAsc 轻量装配 + $gid 访问过滤；
 * block_cache 3600s（随机结果一旦入缓存，一小时内整站同键固定——刷新换图靠缓存过期，非每次请求重洗）。
 * @param array $arr 模板块参数：n=条数（缺省 20，服务层封顶 20）
 * @return array|NULL 视频条目数组；候选窗口为空 NULL（block_cache 原样透传）
 */
function block_vod_list_rand($arr)
{
    // hook model_block_vod_block_vod_list_rand_start.php


    $cid = $_SESSION['cid'];
    $n   = empty($arr['n']) ? 20 : intval($arr['n']);
    $key = block_key('vod_list_rand', $cid, 'n' . $n);
    // hook model_block_vod_block_vod_list_rand_end.php
    return block_cache($key, 3600, function () use ($cid, $n) {
        return \App\Service\Block\VodBlockService::random($cid, $n);
    });
}

// ──────────────────────────────────────────────
// 内部辅助：单分类视频拉取（合并 5 个重复函数）
// ──────────────────────────────────────────────

/**
 * 内部辅助（下划线前缀，**不是模板块位**）：单分类结构化拉取的公共实现，
 * 上方段落注释记的「合并 5 个重复函数」即它的来历——block_vod_list / vod_cid / index_cid /
 * index_type / list_type 五个块位数据链路完全相同，只有缓存键前缀不同，故统一收在这里。
 * 转发 VodBlockService::single($cid, $n)：按 vid 降序取该分类前 N 条轻量行，
 * 外加分类名与分类链接，返回固定四键结构。
 * 缓存键 = block_key($prefix, $cid, 'n'.$n)，TTL 3600s ⇒ **同数据在不同块位各存一份**
 * （换块位不会互相失效，这是刻意设计：按块位独立失效）。
 * @param mixed $cid 分类 ID（空分类时服务层 listByCid 与 cate_read 均返回空 ⇒ videolist 坍缩为空数组）
 * @param int $n 条数（调用方均已在壳层 intval 过，缺省口径 20）
 * @param string $prefix 块位缓存键前缀（决定这份缓存归哪个块位失效）
 * @return array ['videolist' => vid 键轻量行数组, 'cate_name' => string, 'cate_url' => string, 'cid' => int]
 */
function _block_vod_single($cid, $n, $prefix)
{
    // hook model_block_vod__block_vod_single_start.php


    $key = block_key($prefix, $cid, 'n' . $n);
    // hook model_block_vod__block_vod_single_end.php
    return block_cache($key, 3600, function () use ($cid, $n) {
        return \App\Service\Block\VodBlockService::single($cid, $n);
    });
}

/**
 * 模板区块 {block:vod_list}（C 类：单分类结构化）：当前栏目上下文（$_SESSION['cid']）的
 * 「最新 N 条 + 分类名/链接」块位，转内部helper _block_vod_single($cid, $n, 'vod_list')
 * → VodBlockService::single()；**分类 id 取自会话，不由参数指定**，所以只在本页栏目内有效。
 * 缓存键带 cid 与 n，TTL 3600s；同一请求内多次调用命中 block_cache 的 static，不会重复回源。
 * ⚠ cid 依赖会话：在列表页/频道页之外调用会拿到空 cid 的块位（坍缩成空 videolist），
 *   需要显式指定分类请用 {block:vod_cid} / {block:index_cid}。
 * @param array $arr 模板块参数：n=条数（缺省 20，内部 intval）
 * @return array ['videolist' => vid 键轻量行数组, 'cate_name' => string, 'cate_url' => string, 'cid' => int]；无视频时 videolist 为空数组
 */
function block_vod_list($arr)
{
    // hook model_block_vod_block_vod_list_start.php


    $n = empty($arr['n']) ? 20 : intval($arr['n']);
    // hook model_block_vod_block_vod_list_end.php
    return _block_vod_single($_SESSION['cid'], $n, 'vod_list');
}

/**
 * 模板区块 {block:vod_cid}：指定分类的单分类结构化数据块。
 * 转 _block_vod_single($cid, n, 'vod_cid') → VodBlockService::single()，缓存键前缀区分块位（同数据不同块位各存一份缓存）。
 * @param array $arr 模板块参数：cid=分类 ID（缺省 ''=全站）、n=条数（缺省 20）
 * @return array ['videolist'=>vid 键轻量行, 'cate_name'=>string, 'cate_url'=>string, 'cid'=>int]（无视频时 videolist 为空数组）
 */
function block_vod_cid($arr)
{
    // hook model_block_vod_block_vod_cid_start.php


    $cid = empty($arr['cid']) ? '' : $arr['cid'];
    $n   = empty($arr['n']) ? 20 : intval($arr['n']);
    // hook model_block_vod_block_vod_cid_end.php
    return _block_vod_single($cid, $n, 'vod_cid');
}

/**
 * 模板区块 {block:index_cid}：首页「栏目视频」位——数据链路与 block_vod_cid 完全相同（_block_vod_single → VodBlockService::single），
 * 仅缓存键前缀 'index_cid' 不同，便于按块位独立失效。
 * @param array $arr 模板块参数：cid（缺省 ''）、n（缺省 20）
 * @return array|NULL 同 block_vod_cid
 */
function block_index_cid($arr)
{
    // hook model_block_vod_block_index_cid_start.php


    $cid = empty($arr['cid']) ? '' : $arr['cid'];
    $n   = empty($arr['n']) ? 20 : intval($arr['n']);
    // hook model_block_vod_block_index_cid_end.php
    return _block_vod_single($cid, $n, 'index_cid');
}

/**
 * 模板区块 {block:index_type}：首页「按类型/栏目」聚合位——数据链路与 _block_vod_single 同（VodBlockService::single），
 * 仅缓存键前缀 'index_type' 不同，供模板按块位区分取数与失效。
 * @param array $arr 模板块参数：cid（缺省 ''）、n（缺省 20）
 * @return array 同 block_vod_cid 结构
 */
function block_index_type($arr)
{
    // hook model_block_vod_block_index_type_start.php


    $cid = empty($arr['cid']) ? '' : $arr['cid'];
    $n   = empty($arr['n']) ? 20 : intval($arr['n']);
    // hook model_block_vod_block_index_type_end.php
    return _block_vod_single($cid, $n, 'index_type');
}

/**
 * 模板区块 {block:list_type}：列表页类型聚合位——cid 取 $_SESSION['cid']（当前栏目），走 _block_vod_single 前缀 'list_type'。
 * @param array $arr 模板块参数：n（缺省 20）
 * @return array 同 block_vod_cid 结构（videolist 可空）
 */
function block_list_type($arr)
{
    // hook model_block_vod_block_list_type_start.php


    $n = empty($arr['n']) ? 20 : intval($arr['n']);
    // hook model_block_vod_block_list_type_end.php
    return _block_vod_single($_SESSION['cid'], $n, 'list_type');
}

// ──────────────────────────────────────────────
// C 类：单分类带排行
// ──────────────────────────────────────────────

/**
 * 模板区块 {block:vod_list_top}（C 类：单分类带排行）：当前栏目（$_SESSION['cid']）的
 * **热度榜**块位，转发 VodBlockService::singleTop($cid, $n)——结构与 block_vod_list 相同
 * （videolist / cate_name / cate_url / cid 四键），但 videolist 来自累计 hits 榜而非最新入库，
 * 条目由 normalizeRankItems 规整（榜单形态，非主表原始列）。
 * 缓存键 block_key('vod_list_top', cid, 'n'.$n)，TTL 3600s。
 * ⚠ 与 block_vod_dwm 的分工：本块位是**总榜**（无时间窗），要日/周/月窗口请用 {block:vod_dwm}。
 * ⚠ 未传 $empty 兜底，服务层空数据返回空数组，模板可直接 loop；跨栏目误用（会话无 cid）会得到空榜。
 * @param array $arr 模板块参数：n=榜单条数（缺省 20，内部 intval）
 * @return array ['videolist' => 榜单条目数组, 'cate_name' => string, 'cate_url' => string, 'cid' => int]
 */
function block_vod_list_top($arr)
{
    // hook model_block_vod_block_vod_list_top_start.php


    $cid = $_SESSION['cid'];
    $n   = empty($arr['n']) ? 20 : intval($arr['n']);
    $key = block_key('vod_list_top', $cid, 'n' . $n);
    // hook model_block_vod_block_vod_list_top_end.php
    return block_cache($key, 3600, function () use ($cid, $n) {
        return \App\Service\Block\VodBlockService::singleTop($cid, $n);
    });
}

// ──────────────────────────────────────────────
// D 类：一级分类结构化
// ──────────────────────────────────────────────

/**
 * 模板区块 {block:vod_cate}（D 类：一级分类结构化）：频道页装配位，
 * 转发 VodBlockService::channel($cid, $catelist, $cate_nav, $pagesize) ——
 * 一次给出「本频道（含子分类并集）视频列表 + hits 榜 + 频道节点 + 总数」。
 * $catelist/$cate_nav 取自 global（引导层预载的分类表与导航映射），**本函数不再查栏目表**，
 * 因此调用时机在引导之后，缺载时分类并集为空。
 * ⚠ 缓存键 block_key('vod_cate', cid, pagesize)：$arr['n'] 未给时 pagesize 是空串、
 *   进服务层前才被 intval 成 **0**（页大小 0 的查询语义由服务层决定），键也会跟着变成空串形态
 *   ⇒ 模板调用方务必传 n。
 * @param array $arr 模板块参数：cid=频道根分类（缺省 ''）、n=列表页大小（缺省空 ⇒ 服务层收到 0）
 * @return array ['videolist' => 条目数组, 'top' => 规整后的榜单, 'cate' => 频道节点（缺失为空数组）, 'videos' => int 总数]
 */
function block_vod_cate($arr)
{
    // hook model_block_vod_block_vod_cate_start.php


    global $catelist, $cate_nav;
    $cid      = empty($arr['cid']) ? '' : $arr['cid'];
    $pagesize = empty($arr['n']) ? '' : $arr['n'];
    $key      = block_key('vod_cate', $cid, $pagesize);
    // hook model_block_vod_block_vod_cate_end.php
    return block_cache($key, 3600, function () use ($catelist, $cate_nav, $cid, $pagesize) {
        return \App\Service\Block\VodBlockService::channel($cid, $catelist, $cate_nav, intval($pagesize));
    });
}

// ──────────────────────────────────────────────
// E 类：多分类嵌套（vod + art 双模式）
// ──────────────────────────────────────────────

/**
 * 视频多分类嵌套 — 遍历一级分类，聚合子分类视频列表+排行
 */
function _block_vod_cates_vod(array $cate_nav, array $catelist, int $page, $pagesize, $topsize): array
{
    // hook model_block_vod__block_vod_cates_vod_start.php


    // hook model_block_vod__block_vod_cates_vod_end.php
    return \App\Service\Block\VodBlockService::channels('vod', $cate_nav, $catelist, intval($pagesize), intval($topsize));
}

/**
 * 文章多分类嵌套 — 遍历一级分类，聚合子分类文章列表+排行
 */
function _block_vod_cates_art(array $cate_nav, array $catelist, int $page, $pagesize, $topsize): array
{
    // hook model_block_vod__block_vod_cates_art_start.php


    // hook model_block_vod__block_vod_cates_art_end.php
    return \App\Service\Block\VodBlockService::channels('art', $cate_nav, $catelist, intval($pagesize), intval($topsize));
}

/**
 * 模板区块 {block:vod_cates}：多分类嵌套大页——遍历一级分类聚合「子分类列表 + 榜单」。
 * 按 $arr['type'] 分发：'vod'/'art' 走 VodBlockService::channels($type, $cate_nav, $catelist, pagesize, topsize)，
 * 其余取值直接空数组（缓存内同样落空数组）。global $catelist/$cate_nav 由引导层预载，本函数不再查栏目表。
 * @param array $arr 模板块参数：type=vod|art（必填才有数据）、n=列表条数、top=榜单条数
 * @return array cid => ['cate','top','list','videos'(vod)|'arts'(art)] 映射；block_cache 3600s
 */
function block_vod_cates($arr)
{
    // hook model_block_vod_block_vod_cates_start.php


    global $catelist, $cate_nav;
    $type     = empty($arr['type']) ? '' : $arr['type'];
    $pagesize = empty($arr['n']) ? '' : $arr['n'];
    $topsize  = empty($arr['top']) ? '' : $arr['top'];
    $key      = block_key('vod_cates', $type, 'n' . $pagesize, 't' . $topsize);
    // hook model_block_vod_block_vod_cates_end.php
    return block_cache($key, 3600, function () use ($catelist, $cate_nav, $type, $pagesize, $topsize) {
        if ($type === 'vod') {
            return _block_vod_cates_vod($cate_nav, $catelist, 1, $pagesize, $topsize);
        }
        if ($type === 'art') {
            return _block_vod_cates_art($cate_nav, $catelist, 1, $pagesize, $topsize);
        }
        return array();
    });
}

// ──────────────────────────────────────────────
// F 类：多分类独立
// ──────────────────────────────────────────────

/**
 * 模板区块 {block:index_cids}（F 类：多分类独立）：一次给多个分类各出一份「最新 N 条」块位数据，
 * 参数 cid 是**逗号分隔的分类 id 串**（如 `1,2,3`），壳内 explode 后用 array_filter_empty 去空值，
 * 转发 VodBlockService::singleBatch($cids, $n)：批量预取把原来的逐 cid 循环压成
 * 「1 次 vid 索引查询 + 1 次视频批量拉取」（N+1 红线 §三.2 的修法），返回结构与旧循环一致。
 * 结果形状：`cid => ['videolist','cate_name','cate_url','cid']`，即 block_vod_cid 的结构按 cid 再包一层。
 * ⚠ $arr['type'] 只参与**缓存键**生成、没有透传给服务层 ⇒ 换 type 只会分裂出另一份缓存，
 *   数据完全相同（现状记录，勿据此以为能切内容形态）。
 * ⚠ n 未传时为 ''，进服务层前 intval 成 0（等于每类 0 条）；键也带 'n' 空串形态 ⇒ 模板务必传 n。
 * @param array $arr 模板块参数：cid=逗号分隔分类串（必填）、n=每类条数（必填，内部 intval）、type=仅进缓存键
 * @return array cid => 单分类块位结构（缺失分类不出现）；无数据时为空数组
 */
function block_index_cids($arr)
{
    // hook model_block_vod_block_index_cids_start.php


    $type = empty($arr['type']) ? '' : $arr['type'];
    $cid  = empty($arr['cid']) ? '' : $arr['cid'];
    $n    = empty($arr['n']) ? '' : $arr['n'];
    $cids = explode(",", $cid);
    $cids = array_filter_empty($cids);
    $key  = block_key('index_cids', $cid, 'n' . $n, 't' . $type);
    // hook model_block_vod_block_index_cids_end.php
    return block_cache($key, 3600, function () use ($cids, $n) {
        // 批量预取（N+1 → 2 次查询），返回结构与原逐 cid 循环一致
        return \App\Service\Block\VodBlockService::singleBatch($cids, intval($n));
    });
}

// ──────────────────────────────────────────────
// A 类：日/周/月排行视频
// ──────────────────────────────────────────────

/**
 * 模板区块 {block:vod_dwm}（A 类：日/周/月排行视频）：全站时间窗热度位，
 * 转发 VodBlockService::hitPeriod($type, $n)——从 vod_top 流水表按窗口聚合取 top-N id，
 * 再 findAsc 轻量装配并按 global $gid 做访问过滤（与主列表同口径）。
 * $type 经服务层 periodType 归一（d/w/m 或完整窗口名均可），**未知取值回落 'hits'（累计榜）**，
 * 所以模板写错 type 不会报错、只会静默变成总榜。
 * $cid 未传（NULL）= 全站；要按当前栏目过滤请用块位 {block:vod_dwm_cid}。
 * ⚠ n 未传时为 ''，进服务层前 intval 成 0 ⇒ 模板调用方必须传 n。
 * @param array $arr 模板块参数：type=窗口（d/w/m，缺省空 = 服务层归一）、n=条数（必填，内部 intval）
 * @return array 视频条目数组；窗口内无排行数据 = 空数组；block_cache 3600s
 */
function block_vod_dwm($arr)
{
    // hook model_block_vod_block_vod_dwm_start.php


    $n    = empty($arr['n']) ? '' : $arr['n'];
    $type = empty($arr['type']) ? '' : $arr['type'];
    $key  = block_key('vod', $type . '_dwm', $n);
    // hook model_block_vod_block_vod_dwm_end.php
    return block_cache($key, 3600, function () use ($type, $n) {
        return \App\Service\Block\VodBlockService::hitPeriod($type, intval($n));
    });
}

/**
 * 模板区块 {block:vod_dwm_cid}：日/周/月热度排行（按分类）——转发 VodBlockService::hitPeriod($type, n, $cid)，
 * 从 vod_top 流水表按窗口聚合 top-N id → findAsc 轻量装配 → 按 global $gid 做访问过滤（与主列表口径一致）。
 * $arr['cid'] 未传时回落 $_SESSION['cid']（当前栏目），再缺省（列表页外为 0/空）按服务层 $cid 参数处理（NULL=全站，此处传的是 ''/0 值）。
 * @param array $arr 模板块参数：type=d|w|m（缺省 'd'，未知类型服务层回落 'hits'）、n=条数（缺省 8）、cid=分类
 * @return array 视频条目数组；窗口内无排行数据=空数组；block_cache 3600s
 */
function block_vod_dwm_cid($arr)
{
    // hook model_block_vod_block_vod_dwm_cid_start.php


    $n    = empty($arr['n']) ? 8 : intval($arr['n']);
    $type = empty($arr['type']) ? 'd' : $arr['type'];
    $cid  = empty($arr['cid']) ? '' : $arr['cid'];
    if ($cid == '') $cid = $_SESSION['cid'];
    $key = block_key('vod', $type . '_cid', $n, $cid);
    // hook model_block_vod_block_vod_dwm_cid_end.php
    return block_cache($key, 3600, function () use ($type, $cid, $n) {
        return \App\Service\Block\VodBlockService::hitPeriod($type, intval($n), $cid);
    });
}

// ──────────────────────────────────────────────
// A 类：随机海报推荐
// ──────────────────────────────────────────────

/**
 * 模板区块 {block:poster_rand}（A 类：随机海报推荐）：海报位从**置顶候选池**里随机出一份轻量列表，
 * 转发 VodBlockService::posterRand($cid, $n)：取 stickyFindCacheRand 的分页缓存做候选，
 * 只保留 sticky==1（本栏目级）的行，按 create_date 降序（array_multisort_key）后交
 * stickyVideoItems 轻量装配——**渲染期不出网**（§三.4），海报缺失由服务层回落远程 URL 形态。
 * ⚠ 返回条数**不严格等于 n**：n 是候选窗口大小，实际条数 ≤ 窗口内 sticky==1 的数量
 *   （模板按 n 排格子会缺位，展示前自己判数量）。
 * 缓存键带 `v<sticky_video_ver>` 版本令牌 ⇒ 置顶一变即换键自动失效（令牌由置顶维护处
 * cache_set 推进，本块位用 cache_get 读）；此外 TTL 3600s 兜一层。
 * ⚠ 口径提示：§三.1 要求「跨 worker 一致性令牌用 cache_shared_get（跳过 static/apcu）」，
 *   这里与 VideoStickyTrait 读的是同一个令牌却走普通 cache_get ⇒ 极端情况下某 worker 可能
 *   滞后一份令牌（只影响海报刷新时机，不是安全问题），改动前先对齐口径。
 * @param array $arr 模板块参数：cid=分类过滤（缺省 '' = 不限）、n=候选窗口大小（缺省 10，内部 intval）
 * @return array|NULL 视频条目数组；候选窗口为空 = NULL；窗口内没有 sticky==1 行 = 空数组
 */
function block_poster_rand($arr)
{
    // hook model_block_vod_block_poster_rand_start.php


    $cid  = empty($arr['cid']) ? '' : $arr['cid'];
    $n    = empty($arr['n']) ? 10 : intval($arr['n']);
    $sticky_ver = (int)cache_get('sticky_video_ver');
    $key  = block_key('poster_rand', 'cid' . $cid, 'n' . $n, 'v' . $sticky_ver);
    // hook model_block_vod_block_poster_rand_end.php
    return block_cache($key, 3600, function () use ($cid, $n) {
        return \App\Service\Block\VodBlockService::posterRand($cid, $n);
    });
}

// ──────────────────────────────────────────────
// K 类：首页推荐轮播
// ──────────────────────────────────────────────

/**
 * 模板区块 {block:sticky_index_video}（K 类：首页推荐轮播）：整站首页轮播位，
 * 转发 VodBlockService::stickyIndex($n)——取 stickyIndexMap（sticky==1、按 create_date 降序的映射）
 * 前 $n 条，交 stickyVideoItems 轻量装配；**顺序稳定**（array_slice 保序截断，不洗牌），
 * 轮播要随机请改用 {block:poster_rand}。
 * 缓存键 block_key('sticky_index', n, 'v<sticky_video_ver>')：置顶变更即换键失效（同 poster_rand 的令牌口径），
 * 另外 TTL 3600s；block_cache 的 $empty 显式给空数组 ⇒ 无置顶数据时恒返回数组，模板可直接 loop。
 * @param array $arr 模板块参数：n=条数（缺省 10，内部 intval；超出置顶总数时按实际条数返回）
 * @return array 视频条目数组；无置顶数据 = 空数组
 */
function block_sticky_index_video($arr)
{
    // hook model_block_vod_block_sticky_index_video_start.php


    $n   = empty($arr['n']) ? 10 : intval($arr['n']);
    $sticky_ver = (int)cache_get('sticky_video_ver');
    $key = block_key('sticky_index', $n, 'v' . $sticky_ver);
    // hook model_block_vod_block_sticky_index_video_end.php
    $list = block_cache($key, 3600, function () use ($n) {
        return \App\Service\Block\VodBlockService::stickyIndex($n);
    }, array());
    // 轮播模板用 $k==0 判断首张（eager）：统一 0 起索引
    return slide_items_decorate(is_array($list) ? array_values($list) : array());
}
