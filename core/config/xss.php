<?php
/**
 * XSS 过滤中间件配置
 * 递归清洗 GET/POST/JSON 输入，防止 XSS 攻击
 */

return [
    // 总开关
    'enable' => true,
    
    // 允许 HTML 的字段（白名单）
    // 这些字段会进行 HTML 净化而非完全编码
    'allow_html_fields' => [
        'content',           // 文章内容
        'description',       // 描述
        'summary',           // 摘要
        'bio',               // 个人简介
        'signature',         // 签名
        'article.body',      // 文章正文（支持点语法）
        'post.content',      // 帖子内容
    ],
    
    // 是否替换原始 $_GET/$_POST 数据
    // true: 直接修改超全局变量（现有代码无需改动）
    // false: 写入 $_SERVER['XSS_QUERY'] 等属性
    'replace_original' => true,
    
    // 最大递归深度（防止极深嵌套）
    'max_depth' => 32,
    
    // 跳过 XSS 过滤的路由（谨慎使用）
    'skip_routes' => [
        // 'admin-editor',  // 示例：后台编辑器
    ],
];
