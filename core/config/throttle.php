<?php
/**
 * 限流中间件配置
 * 防止暴力破解、DDoS 攻击、恶意爬虫
 */

return [
    // 总开关（2026-10-10 审计 S-8：改为**默认开启**，下方阈值已按上线定标收口）
    // 关闭时：ThrottleMiddleware 直接放行全部请求（不含任何计数/封禁）；
    // 开启后：下方阈值生效，且**持有效 wire 令牌的机器流量仍被豁免**（见 api_token_exempt）。
    // 注意：开启限流可能影响异步采集——商店 rsync-task worker 逐页回调本站 api.php，
    // 若窗口过小或令牌豁免被关掉，会出现 page fail / 任务「异常（error）」。
    // 后台「设置 → 限速设置」页面对此有红色提示，改阈值前请先读那条提示。
    'enable' => true,
    
    // Session 限流（QPS 轮重定标：原 30/60 会误伤带轮询的播放页）
    'session_limit' => 90,      // 单 Session 每分钟最大请求数（上线定标；压测期 1000000 已收口）
    'session_window' => 60,     // Session 时间窗口（秒）
    
    // IP 限流（QPS 轮重定标：原 10/600=1次/分钟，NAT/宽带共享出口的正常用户整片误伤——
    // 压测/扫描的 429 洪水实际全部来自本闸。300/60=持续 5 req/s 超任何真人行为，仍可挡 CC）
    'ip_limit' => 300,          // 单 IP 每分钟最大请求数（上线定标；压测期 1000000 已收口）
    'ip_window' => 60,          // IP 时间窗口（秒）
    
    // 限流（修复轮实测口径：浏览器带 cookie ⇒ nginx L0 页缓存 skipcache 回源，每个页面请求都计数；
    // 播放页一次浏览 = 页面 + ?danmu-list.html 弹幕 XHR 两个计数请求。疯狂 F5 约 45 次/分即触顶 session 90，
    // 触顶后 429 直到 60s 滑窗过期——这是设计行为，不是故障）
    
    // 阶梯惩罚（修复轮：此前 penalty: 键只写不读＝死代码，Retry-After 却外发 6^8*10=16796160 秒）
    'penalty_base' => 60,       // 首次速率违规封禁秒数（与 session_window 同量级）
    'penalty_max' => 300,       // 6^n 增长封顶（秒）；24h 内重复违规最多封 5 分钟
    
    // api.php 机器通道（新增三个键；中间件内亦有同名兜底默认值）
    // 背景：异步采集 worker 逐页回调 `?route=rsync`，一次任务数百上千页——旧的「30 请求/600 秒/IP」
    // 会在第 31 页回 429，worker 重试 10 次后记 `page fail`，任务转 `异常（error）`
    // （真机症状：`page 31: http 429 | page fail`）。
    'api_token_exempt' => true, // 持**有效 wire 令牌**＝已鉴权机器流量（cron/商店 worker/商店推送）⇒ 不限流
    'api_limit' => 30,          // 无令牌 / 令牌错时的洪水窗口请求数（口径与修复前一致，不降低匿名拦截）
    'api_window' => 600,        // 上者时间窗口（秒）

    // 注册限流（saveThrottle() 首次保存时会写入，出厂即带上以免"保存后凭空多出两个键"）
    'register_max' => 10,       // 同 IP 注册次数上限
    'register_window' => 3600,  // 注册限速窗口（秒）

    // 黑名单
    'verify_blacklist' => true, // 启用黑名单检查
    
    // 爬虫验证
    'verify_robot' => false,    // 启用爬虫验证
    'verify_dns' => false,      // 启用 DNS 反查验证爬虫
    
    // 爬虫 User-Agent 关键词
    'bot_list' => [
        'Googlebot',
        'Baiduspider',
        'bingbot',
        'YandexBot',
        'Sogou',
        '360Spider',
        'DuckDuckBot',
    ],
    
    // URL 前缀白名单（这些路由不限流）
    'url_prefix_allow' => [
        'admin',     // 后台
        'api',       // API
    ],
    // 注：真实前台页面为无缓存冷渲染 1.9s/热 30ms（页缓存命中）；限流放行的请求由 FPM(200) 池承接。
    // 容量规划与压测方法见 .skills/deploy/使用说明.md；本文件只决定「放行谁、拦谁」。
    
    // 静态资源扩展名（不限流）
    'static_ext' => [
        'css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 
        'woff', 'woff2', 'ico', 'ttf', 'eot',
    ],
];
