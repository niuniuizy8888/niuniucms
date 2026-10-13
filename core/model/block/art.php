<?php
// hook model_block_art_start.php

// ──────────────────────────────────────────────
// 内部辅助：单分类文章拉取（合并 art_list + art_list_top）
// ──────────────────────────────────────────────

/**
 * 内部辅助（下划线前缀，**不是模板块位**）：单分类文章块的公共实现，段落注释记的来历是
 * 「合并 art_list + art_list_top」两个重复壳——两者只差 finder 回调与缓存键前缀。
 * 结构：block_key($prefix, $cid, 'n' . $n) 组键 → block_cache(3600s) 包住 `$finder($cid, $n)`，
 * 因此返回形态由传进来的回调决定（现在是 ArtBlockService::single / singleTop，都是
 * {artlist, cate_name, cate_url, cid} 四键结构）。
 * ⚠ $finder 是必填的 callable（签名已声明），传非回调会在调用点直接 TypeError；
 *   闭包只捕获 $cid/$n，**$prefix 不进闭包**（只影响缓存键），换前缀即换一份独立缓存。
 * @param mixed $cid 分类 ID（常来自 $_SESSION['cid']）
 * @param int $n 条数（调用方已在壳层 intval，缺省口径 20）
 * @param string $prefix 块位缓存键前缀（决定这份缓存归属与失效）
 * @param callable $finder 取数回调，签名 (cid, n) => array
 * @return mixed 回调返回的结构（一般为 {artlist, cate_name, cate_url, cid}）；缓存层无兜底值时可能为 NULL
 */
function _block_art_single($cid, $n, $prefix, callable $finder)
{
    // hook model_block_art__block_art_single_start.php


    $key = block_key($prefix, $cid, 'n' . $n);
    // hook model_block_art__block_art_single_end.php
    return block_cache($key, 3600, function () use ($cid, $n, $finder) {
        return $finder($cid, $n);
    });
}

// ──────────────────────────────────────────────
// G 类：文章结构化
// ──────────────────────────────────────────────

/**
 * 模板区块 {block:art_list}（G 类：文章结构化）：当前栏目（$_SESSION['cid']）的「最新文章」块位，
 * 经 _block_art_single($cid, $n, 'art_list', ArtBlockService::single) 转发
 * ——服务层是 ArtService::listByCid 的两步取数（索引表取 aid → 主表装配，降序），
 * 并附带分类名/链接（读 cate_read 缓存，缺失时各键以空串兜底，不会报错）。
 * 缓存键 block_key('art_list', cid, 'n'.$n)，TTL 3600s。
 * ⚠ 分类 id 取自会话镜像 ⇒ 在文章栏目页之外调用会拿到空 cid，artlist 坍缩为空数组；
 *   要固定分类请显式用带 cid 参数的块位。
 * @param array $arr 模板块参数：n=条数（缺省 20，内部 intval）
 * @return array {artlist: 文章行数组（无结果归一为空数组）, cate_name, cate_url, cid}
 */
function block_art_list($arr)
{
    // hook model_block_art_block_art_list_start.php


    $n = empty($arr['n']) ? 20 : intval($arr['n']);
    // hook model_block_art_block_art_list_end.php
    return _block_art_single($_SESSION['cid'], $n, 'art_list', array('\App\Service\Block\ArtBlockService', 'single'));
}

/**
 * 模板区块（G 类）：当前分类文章排行结构化数据 —— 经 _block_art_single 调 ArtBlockService::singleTop()（榜单 artlist + cate_name/cate_url/cid），block_cache 3600s。
 * model 层保留此壳是为承载编译管线 hook 锚点（本行以上文件头 hook 属插件内联位）；业务实现勿加回本文件。
 * @param array $arr 区块参数（n=条数，默认 20）；分类取 $_SESSION['cid'] 镜像
 * @return array {artlist, cate_name, cate_url, cid}
 */
function block_art_list_top($arr)
{
    // hook model_block_art_block_art_list_top_start.php


    $n = empty($arr['n']) ? 20 : intval($arr['n']);
    // hook model_block_art_block_art_list_top_end.php
    return _block_art_single($_SESSION['cid'], $n, 'art_list_top', array('\App\Service\Block\ArtBlockService', 'singleTop'));
}

// ──────────────────────────────────────────────
// H 类：文章扁平列表
// ──────────────────────────────────────────────

/**
 * 模板区块 {block:art_list_rand}（H 类：文章扁平列表）：当前栏目上下文的文章随机位，
 * 转发 ArtBlockService::random($cid, $n)（缓存键 block_key('art_list_rand', cid, 'n'.$n)，TTL 3600s）。
 * ⚠ **$cid 只用于取展示用的分类名，不参与筛选**：候选取的是**全站** art_aid 索引最新
 *   max(n*5, 100) 条，洗牌后截 n 个再 findAsc 回表 ⇒ 随机结果可能不属于本栏目，
 *   大站注意「随机」只是最新窗口内随机、不是全表随机（刻意避开 ORDER BY RAND 的全表代价）。
 * ⚠ 随机结果一旦入缓存即一小时固定（同键全站一致），刷新换内容靠缓存过期，不是每次重洗。
 * ⚠ 索引或回表任一环为空时返回 **NULL**（本块位没传 block_cache 的 $empty 兜底值），
 *   模板 loop 前按 falsy 判一下；键里虽然带了 cid，但同一分类的多次调用命中同一份缓存。
 * @param array $arr 模板块参数：n=条数（缺省 20，内部 intval）；分类取 $_SESSION['cid'] 镜像
 * @return array|NULL {list: 按 aid 升序的格式化行, cate_name: 分类名（仅展示）}；任一环无数据 = NULL
 */
function block_art_list_rand($arr)
{
    // hook model_block_art_block_art_list_rand_start.php


    $cid = $_SESSION['cid'];
    $n   = empty($arr['n']) ? 20 : intval($arr['n']);
    $key = block_key('art_list_rand', $cid, 'n' . $n);
    // hook model_block_art_block_art_list_rand_end.php
    return block_cache($key, 3600, function () use ($cid, $n) {
        return \App\Service\Block\ArtBlockService::random($cid, $n);
    });
}

/**
 * 模板区块（H 类）：全站热文章扁平列表 —— block_cache 3600s 包 ArtBlockService::hitPeriod($type, intval($n))（内部 ArtService::hitRows 周期榜取数 → findAsc 装配整行）。
 * $n 空串时 intval=0，下游 hitRankRows 以 max(1,·) 钳成 1 ⇒ 实际只回 1 条（并非 Service 默认 20）；$type 短形式 d/w/m 由 normalizeHitType 归一。
 * @param array $arr 区块参数：n=条数、type=周期类型（空串=''）
 * @return array 文章行列表；无数据空 array
 */
function block_art_dwm($arr)
{
    // hook model_block_art_block_art_dwm_start.php


    $n    = empty($arr['n']) ? '' : $arr['n'];
    $type = empty($arr['type']) ? '' : $arr['type'];
    $key  = block_key('art', $type . '_dwm', $n);
    // hook model_block_art_block_art_dwm_end.php
    return block_cache($key, 3600, function () use ($type, $n) {
        return \App\Service\Block\ArtBlockService::hitPeriod($type, intval($n));
    });
}

/**
 * 模板区块（H 类）：指定分类热文章扁平列表 —— block_cache 3600s 包 ArtBlockService::hitPeriod($type, intval($n), $cid)。
 * 与 block_art_dwm 的差异：默认 n=6、默认 type='d'，$cid 空时回退 $_SESSION['cid']（跟随当前浏览分类）。
 * @param array $arr 区块参数：n/type/cid
 * @return array 文章行列表；无数据空 array
 */
function block_art_dwm_cid($arr)
{
    // hook model_block_art_block_art_dwm_cid_start.php


    $n    = empty($arr['n']) ? 6 : intval($arr['n']);
    $type = empty($arr['type']) ? 'd' : $arr['type'];
    $cid  = empty($arr['cid']) ? '' : $arr['cid'];
    if ($cid == '') $cid = $_SESSION['cid'];
    $key = block_key('art', $type . '_cid', $n, $cid);
    // hook model_block_art_block_art_dwm_cid_end.php
    return block_cache($key, 3600, function () use ($type, $cid, $n) {
        return \App\Service\Block\ArtBlockService::hitPeriod($type, intval($n), $cid);
    });
}
