<?php
// hook model_block_cate_start.php

// ──────────────────────────────────────────────
// I 类：分类树形（已下沉 CateBlockService，以下为兼容壳）
// ──────────────────────────────────────────────

/**
 * 旧模板兼容：参数 cid 规整为逗号分隔的正整数串（防注入/防缓存键污染）。
 * @param mixed $cid 模板传入的 cid（"1,2,3" 形态）
 * @return string 例 "1,2,3"；无有效值返回 ''
 */
function _block_compat_cids($cid)
{
    $out = array();
    foreach (explode(',', (string)$cid) as $v) {
        $v = intval(trim($v));
        $v > 0 && $out[$v] = $v;
        if (count($out) >= 100) break;
    }
    return implode(',', $out);
}

if (!function_exists('block_cate_type')) {
    /**
     * {block:cate_type type="vod|art"}：分类树（兼容壳 → CateBlockService::cateType）
     * @param array $arr type=vod|art
     * @return array
     */
    function block_cate_type($arr)
    {
        // hook model_block_cate_type_start.php
        $arr = is_array($arr) ? $arr : array();
        $type = isset($arr['type']) && in_array($arr['type'], array('vod', 'art'), TRUE) ? $arr['type'] : '';
        return \App\Service\Block\CateBlockService::cateType(array('type' => $type)) ?: array();
    }
}

if (!function_exists('block_type_cid')) {
    /**
     * {block:type_cid cid="1,2,3"}：按 cid 串取分类行（兼容壳 → CateBlockService::typeCid）
     * @param array $arr cid=逗号分隔分类 ID
     * @return array 分类行列表（不存在的 cid 跳过）
     */
    function block_type_cid($arr)
    {
        // hook model_block_type_cid_start.php
        $arr = is_array($arr) ? $arr : array();
        $cid = _block_compat_cids($arr['cid'] ?? '');
        if ('' === $cid) return array();
        return \App\Service\Block\CateBlockService::typeCid(array('cid' => $cid)) ?: array();
    }
}

if (!function_exists('block_cate_cids')) {
    /**
     * {block:cate_cids cid="1,2,3" type="..."}：按 cid 串取分类行（兼容壳 → CateBlockService::cateCids）
     * @param array $arr cid=逗号分隔分类 ID，type 仅入缓存键
     * @return array
     */
    function block_cate_cids($arr)
    {
        // hook model_block_cate_cids_start.php
        $arr = is_array($arr) ? $arr : array();
        $cid = _block_compat_cids($arr['cid'] ?? '');
        if ('' === $cid) return array();
        $type = preg_replace('/[^a-z0-9_]/i', '', (string)($arr['type'] ?? ''));
        return \App\Service\Block\CateBlockService::cateCids(array('cid' => $cid, 'type' => $type)) ?: array();
    }
}

// ──────────────────────────────────────────────
// K 类：旧模板分页块（{block:default_page}/{block:bootstrap_page}/{block:pager_page}，块体为空）兼容壳
// 直接输出当前页分页 HTML（与 $index_page 同源 index_page()），返回空数组；无分页时不输出。
// ──────────────────────────────────────────────

/**
 * 取当前请求的分页 HTML：优先全局 $index_page（首页/筛选/搜索/标签路由），
 * 否则用 route_list_page() 本请求写入的 $_SESSION 分页参数重建（分类/列表页在函数作用域渲染）。
 * @return string
 */
function _block_compat_page_html()
{
    static $done = NULL;
    if (NULL !== $done) return $done;
    if (isset($GLOBALS['index_page']) && is_string($GLOBALS['index_page'])) return $done = $GLOBALS['index_page'];
    $s = isset($_SESSION) && is_array($_SESSION) ? $_SESSION : array();
    if (empty($s['page_url']) || !is_string($s['page_url']) || FALSE === strpos($s['page_url'], '{page}')) return $done = '';
    $num = max(0, intval($s['num'] ?? 0));
    $page = max(1, intval($s['page'] ?? 1));
    $pagesize = max(1, intval($s['pagesize'] ?? 20));
    return $done = (string)index_page(htmlspecialchars($s['page_url'], ENT_QUOTES, 'UTF-8'), $num, $page, $pagesize);
}

if (!function_exists('block_default_page')) {
    /**
     * {block:default_page}{/block}：输出当前页分页（兼容壳）
     * @param array $arr 忽略
     * @return array 恒为空数组
     */
    function block_default_page($arr = array())
    {
        $h = _block_compat_page_html();
        if ('' !== $h) echo '<div class="nn-page">', $h, '</div>';
        return array();
    }
}

if (!function_exists('block_bootstrap_page')) {
    /**
     * {block:bootstrap_page}{/block}：输出当前页分页（兼容壳）
     * @param array $arr 忽略
     * @return array 恒为空数组
     */
    function block_bootstrap_page($arr = array())
    {
        $h = _block_compat_page_html();
        if ('' !== $h) echo '<div class="nn-page">', $h, '</div>';
        return array();
    }
}

if (!function_exists('block_pager_page')) {
    /**
     * {block:pager_page}{/block}：输出当前页分页（兼容壳）
     * @param array $arr 忽略
     * @return array 恒为空数组
     */
    function block_pager_page($arr = array())
    {
        $h = _block_compat_page_html();
        if ('' !== $h) echo '<div class="nn-page">', $h, '</div>';
        return array();
    }
}
