<?php
// 体育直播 · 后台设置页（addon-setting&dir=live，渲染于后台 head/foot 包裹内）
// 数据存 kv（kv_get/kv_set）；写操作走 bull token（admin_require_bull_token 校验 safe_token）。
// 场景令牌：admin_require_bull_token($uid, 'plugin_live_set') 与表单 safe_token 必须同串。

$lvKey = 'plugin_live';
$lvCfg = kv_get($lvKey);
if (!is_array($lvCfg)) {
    $lvCfg = array(
        'on' => 1, 'name' => '体育直播',
        'seo_title' => '{$name}-免费体育直播在线观看-{$sitename}',
        'seo_key' => '体育直播,足球直播,篮球直播,足球比赛,篮球比赛,免费直播',
        'seo_des' => '{$name}：高清足球、篮球赛事免费在线直播，赛程实时更新。播放数据由播放器每次直连直播源获取，本站不做缓存。',
        'player_url' => '', 'width' => '100%', 'height' => '640',
    );
}

/**
 * 播放器地址解析：把后台可改的 player_url 归一成可安全输出的地址，任何不合形态一律回落。
 * ⚠ 与前台 plugins/live/route/live.php **不是完全同一份代码，也不是完全同一口径**：
 *   前台另有两份本函数没有的处理——① 绝对 http(s) 地址再过一次 outbound_url_safe()（纵深防御），
 *   ② 内置地址走根相对而非绝对域名（换域名/HTTPS 迁移时 iframe 不会白屏）。
 *   ⇒ 后果：某个绝对地址可能在后台预览里正常、到前台被静默换成自带播放器；排查此处先看前台那份。
 *   站内 route/ 目录没有 live.php，别去前台路由目录找它。
 *  - 留空 / 非法 ⇒ 回落 '/plugins/live/player/player.html'（插件自带）；
 *  - 站内绝对路径要求单个前导 /：`//host/...` 协议相对形态与含反斜杠的 '\evil.com' 一并拒绝
 *    （浏览器会把它们解析成跳出本站的地址）；也接受显式 http/https 绝对地址；
 *  - 含 < > " ' ` 任一字符直接拒绝——该值最终要进 HTML 属性与 iframe src。
 * @param array $cfg 插件配置（仅读 player_url 键）
 * @return string 可安全输出的播放器地址，恒非空
 */
function lv_setting_player_abs($cfg)
{
    $raw = trim((string)(isset($cfg['player_url']) ? $cfg['player_url'] : ''));
    if ('' !== $raw) {
        $ok = (str_starts_with($raw, '/') && FALSE === strpos($raw, '//', 1) && !str_contains($raw, '\\'))
            || 1 === preg_match('#^https?://#i', $raw);
        if ($ok
            && !str_contains($raw, '<') && !str_contains($raw, '>')
            && !str_contains($raw, '"') && !str_contains($raw, "'")
            && !str_contains($raw, '`')) {
            return $raw;
        }
    }
    return '/plugins/live/player/player.html';
}

if ('POST' == $method) {
    admin_require_bull_token($uid, 'plugin_live_set');
    // 说明：admin_param_text 按字节 substr 截断，中文会切碎产生非法 UTF-8；
    // 此处放宽取入后统一 mb_substr 按字符截断，与表单 maxlength 语义对齐。
    $lvName = trim((string)admin_param_text('name', '', 255));
    $lvName = mb_substr($lvName, 0, 24, 'UTF-8');
    '' === $lvName && $lvName = '体育直播';
    $lvWidth = trim((string)admin_param_text('width', '100%', 8));
    if (!preg_match('#^\d{1,4}(px|%)?$#i', $lvWidth)) $lvWidth = '100%';
    $lvUrl = trim((string)admin_param_text('player_url', '', 255));
    $lvUrlOk = ('' === $lvUrl)
        // '\evil.com' 会被浏览器按协议相对（//evil.com）解析，反斜杠一并拒绝
        || ((str_starts_with($lvUrl, '/') && FALSE === strpos($lvUrl, '//', 1) && !str_contains($lvUrl, '\\')) || 1 === preg_match('#^https?://#i', $lvUrl));
    if (!$lvUrlOk) $lvUrl = '';
    $lvCfg = array(
        'on'         => admin_param_int('on', 0, 0, 1),
        'name'       => $lvName,
        'seo_title'  => mb_substr(trim((string)admin_param_text('seo_title', '', 255)), 0, 160, 'UTF-8'),
        'seo_key'    => mb_substr(trim((string)admin_param_text('seo_key', '', 255)), 0, 200, 'UTF-8'),
        'seo_des'    => mb_substr(trim((string)admin_param_text('seo_des', '', 255)), 0, 300, 'UTF-8'),
        'player_url' => $lvUrl,
        'width'      => $lvWidth,
        'height'     => max(200, min(2000, admin_param_int('height', 640, 200, 2000))),
    );
    kv_set($lvKey, $lvCfg);
    admin_audit_log('live_savecfg', array(
        'on' => $lvCfg['on'], 'height' => $lvCfg['height'],
        'url_custom' => ('' !== $lvCfg['player_url']) ? 1 : 0,
    ));
    message(0, jump('直播设置已保存', url('addon-setting', array('dir' => 'live'), TRUE), 2));
}

$lvSafe = bull_token_get($uid, 'plugin_live_set');
$lvPlayerAbs = htmlspecialchars(lv_setting_player_abs($lvCfg), ENT_QUOTES, 'UTF-8');
?>
<div class="card">
    <div class="card-header">
        <div class="card-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg></div>
        <h3 class="card-title">体育直播 · 设置</h3>
        <div class="card-actions">
            <a class="btn btn-sm btn-secondary" href="?live" target="_blank"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>前台直播</a>
        </div>
    </div>
    <div class="card-body">
        <div class="mb-4 p-3 rounded" style="background:var(--nn-info-light);border:1px solid var(--nn-primary-border);color:var(--nn-text-secondary);font-size:13px;line-height:1.6;">
            前台入口：<a href="?live" target="_blank">/?live</a>。直播数据由内嵌播放器每次直连直播接口实时获取，本站不做任何缓存；页面 L0/页缓存只可能命中静态外壳，与赛程数据无关。
        </div>

        <form method="post">
            <input type="hidden" name="safe_token" value="<?= $lvSafe ?>">
            <div class="form-section">
                <div class="form-section-title"><span class="dot"></span>基本开关</div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="on" value="1" id="lvin_on" <?= !empty($lvCfg['on']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="lvin_on">启用直播页（顶栏入口 + /?live 页面随之生效）</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="lvin_name">导航 / 页面显示名</label>
                        <input class="form-control" type="text" id="lvin_name" name="name" value="<?= xn_h($lvCfg['name']) ?>" maxlength="24">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-title"><span class="dot"></span>SEO 设置<span style="font-weight:400;font-size:12px;color:var(--nn-text-muted);margin-left:8px;">占位符：<code>{$name}</code> 显示名、<code>{$sitename}</code> 站名</span></div>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label" for="lvin_seo_title">标题</label>
                        <input class="form-control" type="text" id="lvin_seo_title" name="seo_title" value="<?= xn_h($lvCfg['seo_title']) ?>" maxlength="160">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="lvin_seo_key">关键词</label>
                        <input class="form-control" type="text" id="lvin_seo_key" name="seo_key" value="<?= xn_h($lvCfg['seo_key']) ?>" maxlength="200">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="lvin_seo_des">描述</label>
                        <textarea class="form-control" id="lvin_seo_des" name="seo_des" rows="2" maxlength="300"><?= xn_h($lvCfg['seo_des']) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-title"><span class="dot"></span>播放器</div>
                <div class="mb-3">
                    <label class="form-label" for="lvin_player_url">播放器页面地址</label>
                    <input class="form-control" type="text" id="lvin_player_url" name="player_url" value="<?= xn_h($lvCfg['player_url']) ?>" maxlength="255" placeholder="/plugins/live/player/player.html">
                    <div class="form-text">留空 = 使用插件自带播放器；也可填站内其它路径或以 http/https 开头的绝对地址。</div>
                </div>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label" for="lvin_width">iframe 宽</label>
                        <input class="form-control" type="text" id="lvin_width" name="width" value="<?= xn_h($lvCfg['width']) ?>" maxlength="8" placeholder="100%">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="lvin_height">iframe 高 (px)</label>
                        <input class="form-control" type="number" id="lvin_height" name="height" value="<?= (int)$lvCfg['height'] ?>" min="200" max="2000">
                    </div>
                </div>
            </div>

            <div class="card-footer" style="padding-left:0;padding-right:0;background:transparent;border-top:1px solid var(--nn-border-light);">
                <button type="submit" class="btn btn-primary">保存设置</button>
            </div>
        </form>

        <hr style="margin:24px 0">

        <div class="form-section-title"><span class="dot"></span>嵌入到其它页面 / 模板</div>
        <div class="mb-2 text-muted" style="font-size:13px;">当前生效播放器地址：<code><?= $lvPlayerAbs ?></code></div>
        <textarea class="form-control" rows="3" readonly style="font-family:monospace" onclick="this.select()">&lt;iframe src="<?= $lvPlayerAbs ?>" width="100%" height="560" frameborder="0" allowfullscreen&gt;&lt;/iframe&gt;</textarea>
        <div class="form-text">提示：嵌入若使用前台播放器页（/?live），iframe 高度可后台调整；移动端建议用较矮高度（如 420）。</div>
    </div>
</div>

