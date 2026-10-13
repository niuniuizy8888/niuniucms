<?php
/**
 * 牛牛CMS 安装入口（install/ 目录迁移至 core/install/，根目录仅保留本引导文件）
 * 未安装时首页自动 302 跳转本入口；已安装时由 core/install/index.php 双重拦截拒绝重装
 */
define('APP_PATH', __DIR__ . '/');
define('INSTALL_PATH', APP_PATH . 'core/install/');
include INSTALL_PATH . 'index.php';
