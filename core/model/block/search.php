<?php
// hook model_block_search_start.php

// ──────────────────────────────────────────────
// J 类：一维关键词（热门搜索）（已下沉 CateBlockService，以下为兼容壳）
// ──────────────────────────────────────────────

if (!function_exists('block_hot_search')) {
    /**
     * {block:hot_search n="10"}：热门搜索词（兼容壳 → CateBlockService::hotSearch）
     * @param array $arr n=条数（1-100，缺省 10）
     * @return array
     */
    function block_hot_search($arr)
    {
        // hook model_block_hot_search_start.php
        $arr = is_array($arr) ? $arr : array();
        $n = intval($arr['n'] ?? 10);
        $n = $n > 0 ? min($n, 100) : 10;
        return \App\Service\Block\CateBlockService::hotSearch(array('n' => $n)) ?: array();
    }
}
