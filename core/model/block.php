<?php
// hook model_block_start.php
// 兼容入口 — 按需加载 block 子模块
// 所有 block_* 函数仍可直接由模板 {block:xxx} 标签调用，函数名不变

require APP_PATH . 'core/model/block/base.php';
require APP_PATH . 'core/model/block/vod.php';
require APP_PATH . 'core/model/block/art.php';
require APP_PATH . 'core/model/block/cate.php';
require APP_PATH . 'core/model/block/search.php';
