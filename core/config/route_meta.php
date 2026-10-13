<?php
/**
 * 前台路由 meta 声明表（P1-8）
 * 结构：route => action => rules
 *  - auth: bool 要求登录（未登录拦截：JSON 错误 / 跳转登录页）
 *  - throttle: array 限流（scene/max/window，POST 生效）
 * 注意：仅做声明式前置校验，不替代路由内既有业务校验
 */
return array(
    'user' => array(
        'login' => array(
            'throttle' => array('scene' => 'login_page', 'max' => 30, 'window' => 300),
        ),
        'passwd' => array('auth' => true),
        'logout' => array('auth' => true),
    ),
    'member' => array(
        'like' => array('auth' => true),
        'art_like' => array('auth' => true),
        'vod_like' => array('auth' => true),
        'favorite' => array('auth' => true),
        'art_favorites' => array('auth' => true),
        'vod_favorites' => array('auth' => true),
        'password' => array('auth' => true),
    ),
);
