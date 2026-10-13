<?php
// 体育直播 · 安装脚本（install 标志写入后经编译管线执行一次；异常自动回滚安装标志）
// 直接执行脚本：不带 hook 的 `<?php exit;` 头（那是 hook/*.php 专属约定）。

// 默认配置写入 kv 表（kv_set 自带缓存失效；键名统一加插件前缀防冲突）
if (NULL === kv_get('plugin_live')) {
    kv_set('plugin_live', array(
        'on'         => 1,     // 1=启用（顶栏入口 + /?live 页面）
        'name'       => '体育直播',   // 顶栏显示名 / 页面标题词
        // SEO 占位符：{$name} / {$sitename}
        'seo_title'  => '{$name}-免费体育直播在线观看-{$sitename}',
        'seo_key'    => '体育直播,足球直播,篮球直播,足球比赛,篮球比赛,免费直播',
        'seo_des'    => '{$name}：高清足球、篮球赛事免费在线直播，赛程实时更新。播放数据由播放器每次直连直播源获取，本站不做缓存。',
        'player_url' => '',    // 留空=使用插件自带播放器（plugins/live/player/player.html）
        'width'      => '100%', // iframe 宽：整数或百分比
        'height'     => '640',  // iframe 高（px，200–2000）
    ));
}
