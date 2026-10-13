<?php exit; ?>
<?php
/* 体育直播 · 顶栏导航入口（hook/head_nav_end.php）
 * 注入位置：template/default/html/public/head.html 导航尾部（HTML 上下文，桌面/移动两处同名位均注入）。
 * 仅开关开启时显示；链接形态与论坛入口一致（/?live，url_rewrite_on=0 下为合法路由形态）。 */
$lvCfg = function_exists('kv_get') ? kv_get('plugin_live') : NULL;
$lvName = (!empty($lvCfg['on']) && isset($lvCfg['name']) && '' !== trim((string)$lvCfg['name']))
    ? (string)$lvCfg['name'] : '体育直播';
if (!empty($lvCfg['on'])) { ?>
<a class="flex items-center cursor-pointer header-tab group relative px-3 py-1 whitespace-nowrap" href="?live" title="体育直播">
    <span class="transition-all duration-200 text-[var(--text-primary)] group-hover:text-[var(--color-primary)]"><?php echo htmlspecialchars($lvName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
</a>
<?php } 
