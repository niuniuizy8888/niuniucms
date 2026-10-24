<?php
namespace App\Service\Admin;

class SetManageService
{
    use \App\Service\Admin\Traits\SetManageWebsiteTrait;
    use \App\Service\Admin\Traits\SetManagePlayerTrait;
    use \App\Service\Admin\Traits\SetManageDanmuTrait;
    use \App\Service\Admin\Traits\SetManageDomainTrait;
    use \App\Service\Admin\Traits\SetManageEsTrait;
    use \App\Service\Admin\Traits\SetManageThrottleTrait;
    /**
     * 「设置 → 路由」页数据装配（GET 侧，调用点 core/admin/route/set.php case 'route'）。
     * @param array $conf 站点配置（取 conf['route']：路由名 => 别名 映射，直接作表单行）
     * @param int   $uid  当前管理员 uid，用于签发场景令牌 admin_set_route
     * @return array {header:{title}, arrlist: 路由别名映射, safe_token: string|FALSE（用户不存在时 bull_token_set 回 FALSE）}
     *         返回的数组供路由层 extract(..., EXTR_SKIP) 注入模板变量
     */
    public static function routePage(array $conf, int $uid): array
    {
        return array(
            'header' => array(
                'title' => lang('route_set'),
            ),
            'arrlist' => $conf['route'],
            'safe_token' => bull_token_set($uid, 'admin_set_route'),
        );
    }

    /**
     * 保存路由别名（POST）：先 verifyToken 复核场景令牌 admin_set_route（不通过即 message 终止，fail-closed），
     * 再把 $_POST['data'] 原样交 ConfigRuntimeService::saveRoutes() 写入 **KV 运行时配置层**
     * （config_runtime_set_values，不物理改写 config.php，CLAUDE.md「当前架构·配置分层」）。
     * ⚠ 取参用 _POST() 而非 admin_param_*：不做转义也不做类型归一，值形态完全由表单决定。
     * ⚠ 历史缺陷（已修）：底层曾按「行数组（每行含 id/rank）」消费表单，而 set_route.html 提交的是
     *   `data[路由名]=别名` 扁平映射 ⇒ PHP 8 下 TypeError，路由页保存 100% 失败。
     *   现底层已改为按扁平映射处理（白名单既有键 + xn_safe_word 归一别名），本处只负责取参与门禁。
     * _POST('data') 是**原始未转义值**（见 core/func/globals.php），可能缺失或根本不是数组
     *   （`data=x` 或 `data[k][]=v` 形态）——底层形参是 array，直传会 TypeError，故本处判型兜底。
     * @param int $uid 当前管理员 uid
     * @return array {code: 0, message: string}——失败口径沿用同族：底层 success=false 时
     *         message(-1, msg) 直接输出提示页并 exit（本方法不返回）
     */
    public static function saveRoutes(int $uid): array
    {
        self::verifyToken($uid, 'admin_set_route');
        $post = _POST('data');
        $result = ConfigRuntimeService::saveRoutes(is_array($post) ? $post : array());
        $result['success'] || message(-1, $result['msg']);
        return array('code' => 0, 'message' => $result['msg']);
    }

    /**
     * 「设置 → 运行状态」页数据装配（GET，只读页无写操作 ⇒ 不带 safe_token）。
     * @param array $conf 站点配置（透传给 layersInfo() 读 cache.multi）
     * @return array {header:{title}, input:{op: HTML 提示条字符串}}；该 HTML 由 layersInfo() 生成，
     *         内容仅含配置里的驱动名与固定文案，模板侧按原样输出（属既有「后台可渲染 HTML」口径）
     */
    public static function opPage(array $conf): array
    {
        return array(
            'header' => array(
                'title' => lang('set_op'),
            ),
            'input' => array(
                'op' => self::layersInfo($conf),
            ),
        );
    }

    /**
     * 「设置 → 伪静态」页数据装配（GET）：四个 rewrite 档位单选 + 场景令牌 admin_set_rw。
     * @param array $conf 站点配置（读 url_rewrite_on，缺省 0=默认非静态）
     * @param int   $uid  当前管理员 uid
     * @return array {header:{title}, input:{rewrite: form_radio 生成的 HTML}, safe_token: string|FALSE}
     */
    public static function rewritePage(array $conf, int $uid): array
    {
        $rewrite = array('0' => '默认', '1' => '伪静态1', '2' => '伪静态2', '3' => '伪静态3');
        return array(
            'header' => array(
                'title' => lang('set_rw'),
            ),
            'input' => array(
                'rewrite' => form_radio('rewrite', $rewrite, array_value($conf, 'url_rewrite_on', 0)),
            ),
            'safe_token' => bull_token_set($uid, 'admin_set_rw'),
        );
    }

    /**
     * 保存伪静态档位（POST）：verifyToken(admin_set_rw) → ConfigBootstrapService::saveRewrite()。
     * ⚠ 这一项不走 KV 运行时层：伪静态/站点路径/cookie 域是**启动期配置**（入口在 KV 注入前就要读），
     *   由 ConfigBootstrapService 物理改写 core/config/config.php。入参经 admin_param_int 钳制在 0-3。
     * @param int $uid 当前管理员 uid
     * @return array {code: 0, message: string}（底层 success 键在此被丢弃，失败靠 message()/异常中断表达）
     */
    public static function saveRewrite(int $uid): array
    {
        self::verifyToken($uid, 'admin_set_rw');
        $result = ConfigBootstrapService::saveRewrite(admin_param_int('rewrite', 0, 0, 3));
        return array('code' => 0, 'message' => $result['msg']);
    }

    /**
     * 「设置 → 缓存」页数据装配（GET）：两个清理勾选框 + 场景令牌 admin_set_cache。
     * @param int $uid 当前管理员 uid
     * @return array {header:{title}, input:{clear_tmp, clear_cache: form_checkbox HTML}, safe_token: string|FALSE}
     */
    public static function cachePage(int $uid): array
    {
        return array(
            'header' => array(
                'title' => lang('set_cache'),
            ),
            'input' => array(
                'clear_tmp' => form_checkbox('clear_tmp', 1),
                'clear_cache' => form_checkbox('clear_cache'),
            ),
            'safe_token' => bull_token_set($uid, 'admin_set_cache'),
        );
    }

    /**
     * 执行清缓存（POST）：verifyToken(admin_set_cache) → SystemMaintenanceService::clearCache()。
     * 动作（在底层）：清 30 天前排行历史 →（勾选时）cache_truncate + 页缓存版本令牌强制推进 → 重置 website KV 的
     * grouplist 镜像 →（勾选时）rmdir_recusive 删编译产物 → tmpl_prewarm 预热 → 审计 cache_cleanup。
     * @param array $conf 站点配置（仅取 conf['cache_path'] 作为待清理的编译产物目录）
     * @param int   $uid  当前管理员 uid
     * @return array {code: 0, message: string}（底层 clearCache 恒 success=true，本层不透传 success 键）
     */
    public static function clearCache(array $conf, int $uid): array
    {
        self::verifyToken($uid, 'admin_set_cache');
        $result = SystemMaintenanceService::clearCache(
            admin_param_int('clear_tmp'),
            admin_param_int('clear_cache'),
            $conf['cache_path']
        );
        return array('code' => 0, 'message' => $result['msg']);
    }

    /**
     * 「设置 → 系统升级」页数据装配（GET）：版本显示值现查商店（CloudOpsService::cloudVersionFresh 忽略 600s 缓存），
     * 取不到（商店不可达 / cloud_api 未配置 = null）才沿用路由传入的 $cloudVersion。
     * 原因见方法内注释：缓存冷时路由层会拿本站版本兜底，直接把本站版本当「云端版本」显示并写回线上 config。
     * @param int    $uid           当前管理员 uid（签发场景令牌 admin_set_upgrade）
     * @param string $cloudVersion  路由层带来的云端版本号（兜底展示值）
     * @return array {header:{title:'系统升级'}, input:{safe_token: form_hidden HTML, upgrade: form_radio HTML}}
     */
    public static function upgradePage(int $uid, string $cloudVersion): array
    {
        // 升级页显示的"云端版本"必须以商店**当下自陈**为准：入口只读 600s 缓存（F-01 加固），
        // 缓存冷时 route 层会拿本站当前版本兜底 ⇒ 页面会显示"升级到云端版本 <本站版本>"
        // 并把该值写回线上 config（升级后版本不前进）。取不到（商店不可达）则沿用原值。
        $fresh = \App\Service\System\CloudOpsService::cloudVersionFresh();
        if (is_string($fresh) && '' !== $fresh) $cloudVersion = $fresh;
        return array(
            'header' => array(
                'title' => '系统升级',
            ),
            'input' => array(
                'safe_token' => form_hidden('safe_token', bull_token_set($uid, 'admin_set_upgrade')),
                'upgrade' => form_radio('type', array('1' => '升级到云端版本 ' . $cloudVersion), 1),
            ),
        );
    }

    /**
     * 执行系统升级（POST）：verifyToken(admin_set_upgrade) → 版本再次现查商店 → SystemMaintenanceService::upgrade()。
     * 失败口径：底层 success=false 时 `message(-1, msg)` 直接输出提示页并 exit（本方法不返回）；
     * 成功返回 jump() 生成的「3 秒后跳回升级页」消息（外链会先经 UrlSafetyService 确认页）。
     * ⚠ 实际升级由 CloudOpsService::updateDown 落盘（下载 + copy_recusive 覆盖 + 版本号写入），属高危写操作，
     *   权限位 manageset 在路由层把守（本层只有 CSRF 场景令牌这一道）。
     * @param int    $uid           当前管理员 uid
     * @param string $cloudVersion  期望升级到的版本号（本层会用现查结果覆盖）
     * @param string $cachePath     编译产物目录（升级后强制清理）
     * @return array {code: 0, message: string} 仅在成功路径到达
     */
    public static function upgrade(int $uid, string $cloudVersion, string $cachePath): array
    {
        self::verifyToken($uid, 'admin_set_upgrade');
        // 同 upgradePage：POST 路径也要先向商店确认版本，避免把缓存里的旧版本/本站版本
        // 当成"要升级到的版本"写进审计与配置（updateDown 内另有"按包内自陈版本记录"的第二道闸）
        $fresh = \App\Service\System\CloudOpsService::cloudVersionFresh();
        if (is_string($fresh) && '' !== $fresh) $cloudVersion = $fresh;
        $result = SystemMaintenanceService::upgrade($cloudVersion, $cachePath);
        $result['success'] || message(-1, $result['msg']);
        return array('code' => 0, 'message' => jump($result['msg'], url('set-upgrade', array(), TRUE), 3));
    }

    /**
     * 「设置 → SEO」页数据装配（GET）：本方法只给标题与场景令牌，页面字段由模板 set_seo.html 自行渲染。
     * @param int $uid 当前管理员 uid（签发场景令牌 admin_set_seo）
     * @return array {header:{title}, safe_token: string|FALSE}
     */
    public static function seoPage(int $uid): array
    {
        return array(
            'header' => array(
                'title' => lang('seo'),
            ),
            'safe_token' => bull_token_set($uid, 'admin_set_seo'),
        );
    }

    /**
     * 保存单页 SEO 三件套（POST）：verifyToken(admin_set_seo) → ConfigRuntimeService::saveSeo()
     * → 写 KV 运行时层 seo[page]（不物理改写 config.php）。
     * 转义口径在底层：$page 走字符集白名单（防属性位逃逸，审计 M-A1），
     * seo_title/seo_key 经 filter_all_html + htmlspecialchars(ENT_QUOTES)，seo_des 经 xn_html_safe + ENT_QUOTES
     * ——前台 <title>/meta 是属性上下文，白名单过滤不处理引号。
     * @param array $conf 站点配置（作为 seo 现值基底，底层 array 合并后整体回写）
     * @param int   $uid  当前管理员 uid
     * @return array {code: 0, message: string}（底层恒 success=true）
     */
    public static function saveSeo(array $conf, int $uid): array
    {
        self::verifyToken($uid, 'admin_set_seo');
        $result = ConfigRuntimeService::saveSeo(
            admin_param_text('page', '', 50),
            admin_param_text('seo_title', '', 255),
            admin_param_text('seo_key', '', 255),
            admin_param_text('seo_des', '', 500),
            $conf
        );
        return array('code' => 0, 'message' => $result['msg']);
    }

    /**
     * 地址替换页（可**直接下发替换**，不再只是只读展示）
     *
     * 页面数据：① 商店下发的替换记录（`$nnzs['replist']`，模板侧读取）② 资源站下拉（供替换表单选
     * source_id）③ 场景令牌 `admin_set_rep`（POST 必带，走 admin_bull_token_guard）。
     */
    public static function repPage(int $uid): array
    {
        $codes = \App\Service\System\CloudOpsService::cloudPayload();
        $sources = array();
        foreach ((array)($codes['api'] ?? array()) as $code => $v) {
            if (!is_array($v)) continue;
            $sources[] = array(
                'code' => (string)$code,
                'id'   => intval($v['id'] ?? 0),
                'name' => (string)($v['name'] ?? $code),
            );
        }
        return array(
            'header' => array(
                'title' => lang('rep'),
            ),
            'sources' => $sources,
            'safe_token' => bull_token_set($uid, 'admin_set_rep'),
        );
    }

    /**
     * 执行替换（POST）：**先本地执行、再上报商店**（按用户口径重构）
     *
     * 流程：① 校验（场景令牌 + 域名格式 + old≠new + 超时归一）
     *       ② **本地立即生效**——调用 `DomainReplaceService::run()`，与机器通道
     *          `api.php?route=replace` **完全同一代码路径**（列白名单 play_url/pic、
     *          预编译 REPLACE、单次可放宽 PHP 执行时限）
     *       ③ 上报商店 `?api=replace` 登记（勾选"立即推送"则商店登记后向**其它**站点推送）
     * 两步结果分别回报：本地成功而商店上报失败时**不回滚本地**，但明确告知（避免"以为没生效"）。
     *
     * 为什么是"直接调用同一服务"而不是 HTTP 回环打自己的 api.php：本站地址多为内网/回环
     * （实测 192.0.2.100:8744），而出站闸 `outbound_url_safe()` 对私网/回环是硬拦的
     * （红线不允许绕过）——回环请求会被自己的安全闸挡下，且多一份 DNS 依赖与超时预算。
     */
    public static function replaceSubmit(int $uid): array
    {
        self::verifyToken($uid, 'admin_set_rep');
        $old = trim((string)admin_param_text('old_domain', '', 255));
        $new = trim((string)admin_param_text('new_domain', '', 255));
        $type = ('pic' === admin_param_text('type', 'play', 10)) ? 'pic' : 'play';
        $code = admin_param_text('code', '', 32);
        $sid = admin_param_int('source_id', 0, 0);
        $remark = admin_param_text('remark', '', 255);
        $push = admin_param_int('push', 1, 0, 1);
        $timeout = \App\Service\Content\DomainReplaceService::normalizeTimeout(admin_param_int('timeout', 0, 0, 3600));
        if ('' === $code && $sid <= 0) {
            return array('code' => -1, 'message' => '请选择资源站（替换记录必须归属某个采集源）');
        }
        if (!\App\Service\Content\DomainReplaceService::validDomain($old)
            || !\App\Service\Content\DomainReplaceService::validDomain($new)) {
            return array('code' => -1, 'message' => '域名格式非法：只允许字母数字点横线，可带端口（不含 http:// 与路径）');
        }
        if ($old === $new) {
            return array('code' => -1, 'message' => '新旧域名相同，无需替换');
        }

        // ② 本地立即执行（与 api.php?route=replace 同一服务）
        $local = \App\Service\Content\DomainReplaceService::run(array(
            'type' => $type, 'old_domain' => $old, 'new_domain' => $new, 'timeout' => $timeout,
        ));
        if (0 !== $local['code']) {
            admin_audit_log('set_rep_submit', array('stage' => 'local_fail', 'type' => $type, 'old' => $old, 'new' => $new, 'msg' => $local['msg']));
            return array('code' => -1, 'message' => '本地替换失败：' . $local['msg']);
        }

        // ③ 上报商店（登记 + 可选推送其它站点）
        $up = \App\Service\System\CloudOpsService::storeReplaceSubmit(array(
            'source_id' => $sid, 'code' => $code, 'type' => $type,
            'old_domain' => $old, 'new_domain' => $new, 'remark' => $remark, 'push' => $push,
        ));
        admin_audit_log('set_rep_submit', array(
            'source' => $code, 'source_id' => $sid, 'type' => $type, 'old' => $old, 'new' => $new,
            'timeout' => $timeout, 'local_updated' => intval($local['updated']),
            'store_ok' => $up['ok'] ? 1 : 0, 'store_msg' => mb_substr((string)$up['msg'], 0, 200),
        ));
        $localMsg = '本站已替换 ' . intval($local['updated']) . ' 行（' . ('play' === $type ? 'play_url' : 'pic') . '，时限 ' . $timeout . 's）';
        if (!$up['ok']) {
            return array('code' => 0, 'message' => $localMsg . '；⚠️ 商店上报失败：'
                . ($up['msg'] !== '' ? $up['msg'] : '商店无响应') . '（本站已生效，不影响其它站点由商店侧补登）');
        }
        $tail = !empty($up['data']['pushed']) ? '；商店已开始向其它站点推送' : '（未触发推送，可在商店后台「替换管理」手动推送）';
        return array('code' => 0, 'message' => $localMsg . '；商店已登记：' . ($up['msg'] !== '' ? $up['msg'] : 'ok') . $tail);
    }

    /**
     * 「从商店拉取并应用」——局域网/内网部署的替换落地通道
     *
     * 为什么需要：商店的**推送**通道（worker `/push-replacements`）按 M4 安全审查**无条件拒绝私网目标**
     * （推送会携带该站安装令牌，属刻意收紧，Go 侧 `TestFetchAndPushIPPolicy` 锁定该口径），因此当商店与
     * 站点同处局域网时推送必然失败（真机实测 `replace_pushes`：`目标 IP 被 SSRF 闸拒绝：192.0.2.100`）。
     * 站点主动拉取不受此限，且不需要商店能反向访问站点。
     *
     * 应用口径与接收链 `api.php?route=store_push` **完全一致**：列白名单（play→`play_url` / pic→`pic`）、
     * 域名格式闸、old≠new、`REPLACE(col, old, new) WHERE col LIKE %old%`（预处理绑定）。
     */
    public static function replacePull(int $uid): array
    {
        self::verifyToken($uid, 'admin_set_rep');
        list($rows, $err) = \App\Service\System\CloudOpsService::storeReplacements();
        if ('' !== $err) return array('code' => -1, 'message' => '拉取失败：' . $err);
        if (!$rows) return array('code' => 0, 'message' => '商店最近 30 天没有替换记录，无需应用');
        $timeout = \App\Service\Content\DomainReplaceService::normalizeTimeout(admin_param_int('timeout', 0, 0, 3600));
        $r = \App\Service\Content\DomainReplaceService::runBatch($rows, $timeout);
        admin_audit_log('set_rep_pull', array(
            'rows' => intval($r['rows']), 'applied' => intval($r['applied']),
            'changed' => intval($r['changed']), 'skipped' => intval($r['skipped']), 'timeout' => $timeout,
        ));
        return array('code' => 0, 'message' => '已拉取 ' . intval($r['rows']) . ' 条替换并应用：命中 ' . intval($r['applied']) . ' 条，'
            . '实际改写 ' . intval($r['changed']) . ' 行' . ($r['skipped'] ? '，跳过非法/无效 ' . intval($r['skipped']) . ' 条' : '')
            . '（时限 ' . $timeout . 's）');
    }

    /**
     * 「设置 → 清空所有数据」确认页数据装配（GET）：勾选框 clear_data 是唯一的执行开关（防误触）。
     * @param int $uid 当前管理员 uid（签发场景令牌 admin_rm）
     * @return array {header:{title:'清空所有数据'}, input:{clear_data: form_checkbox HTML, safe_token: form_hidden HTML}}
     */
    public static function clearDataPage(int $uid): array
    {
        return array(
            'header' => array(
                'title' => '清空所有数据',
            ),
            'input' => array(
                'clear_data' => form_checkbox('clear_data', 1),
                'safe_token' => form_hidden('safe_token', bull_token_set($uid, 'admin_rm')),
                'confirm_word' => form_text('confirm_word', ''),
            ),
        );
    }

    /**
     * 执行全站数据清空（POST）——**破坏性最高**的后台动作：verifyToken(admin_rm) → SystemMaintenanceService::clearAllData()。
     * 底层按 16 表白名单 truncate（内容/分类/标签/会话/排行/缓存），不动 user/group/config/audit；
     * 未勾选 clear_data、或部分表 truncate 失败时底层返回 success=false ⇒ 本层 `message(-1, msg)`
     * 输出提示页并 exit（msg 会带失败表清单）。
     * 逐表 truncate 的结果现已由底层如实上报并聚合进 data_clear 审计的 failed_tables 字段。
     * @param array $conf 站点配置（仅取 conf['cache_path']）
     * @param int   $uid  当前管理员 uid
     * @return array {code: 0, message: string} 仅在成功路径到达
     */
    public static function clearData(array $conf, int $uid): array
    {
        self::verifyToken($uid, 'admin_rm');
        if ('清空' !== trim((string)param('confirm_word', '', FALSE))) {
            return array('code' => 1, 'message' => '请在确认框中输入「清空」两个字后再提交');
        }
        $result = SystemMaintenanceService::clearAllData(
            admin_param_int('clear_data', 0, 0, 1),
            $conf['cache_path']
        );
        $result['success'] || message(-1, $result['msg']);
        return array('code' => 0, 'message' => $result['msg']);
    }

    /**
     * 本类写操作的统一 CSRF/会话校验（fail-closed）：param('safe_token') 取值后交 admin_bull_token_guard。
     * 校验不通过时由 guard 按失败分类（stale_token/session_expired/session_empty/other_session）
     * 写对应审计并 `message()` 输出提示页 + exit ⇒ **不返回、不抛异常**，调用方无需判返回值。
     * 令牌绑 uid + 管理员密码哈希 + ip/UA/时效（S-7），场景名必须与页面签发时一致，否则环里查不到即拒。
     * @param int    $uid   目标管理员 uid
     * @param string $scene 会话场景指纹键（如 admin_set_seo / admin_rm）
     * @return void
     */
    /**
     * 「设置 → 密钥轮换」页数据（GET，仅超级管理员；门禁在 route/set.php）。
     * 只展示当前 api 派生令牌的掩码，auth_key 本体不出现在页面。
     * @return array {header, safe_token, api_token_mask, legacy_on}
     */
    public static function authKeyPage(int $uid): array
    {
        $tok = function_exists('api_wire_token') ? (string)api_wire_token('api') : '';
        $conf = (array)_SERVER('conf');
        return array(
            'header' => array('title' => '更换 auth_key'),
            'safe_token' => bull_token_set($uid, 'admin_authkey'),
            'api_token_mask' => '' === $tok ? '（未配置）' : substr($tok, 0, 4) . str_repeat('*', 24) . substr($tok, -4),
            'legacy_on' => 1 === intval($conf['api_legacy_token'] ?? 0),
        );
    }

    /**
     * 一键更换 auth_key（POST，仅超级管理员；CSRF 场景 admin_authkey；需输入确认词「更换」）。
     * 效果：config.php 写入新的 64 位随机 auth_key（回读校验）⇒ 后台 admin_token、前台免登录令牌、
     * api.php 派生令牌全部失效；同时清空 session/session_data 表，所有已登录会话（含本人）立即下线。
     * 返回新 api 令牌（仅本次显示一次）与后续操作提示。
     * @return array {code, message}
     */
    public static function rotateAuthKey(int $uid): array
    {
        self::verifyToken($uid, 'admin_authkey');
        if ('更换' !== trim((string)param('confirm_word', '', FALSE))) {
            return array('code' => 1, 'message' => '请在确认框中输入「更换」两个字后再提交');
        }
        $file = \nncms_runtime_path_w('config.php');
        if (!is_file($file) || !is_writable($file)) {
            return array('code' => 1, 'message' => '更换失败：core/config/config.php 不可写，请检查文件权限（需运行 PHP 的用户可写）');
        }
        $newKey = bin2hex(random_bytes(32));
        // M-2 part2：商店关系令牌与 auth_key 解耦——轮换时若本站尚无（或非法）store_report_token
        // 则补生成一枚随机值一并落盘（此后商店身份不随 auth_key 轮换漂移）；已有合法值则保留不动。
        $conf0 = (array)_SERVER('conf');
        $curStoreTok = isset($conf0['store_report_token']) ? (string)$conf0['store_report_token'] : '';
        $vars = array('auth_key' => $newKey);
        $newStoreTok = '';
        if ('' === $curStoreTok || !preg_match('#^[A-Za-z0-9]{16,64}$#', $curStoreTok)) {
            $newStoreTok = bin2hex(random_bytes(16));
            $vars['store_report_token'] = $newStoreTok;
        }
        $r = file_replace_var($file, $vars);
        clearstatcache(TRUE, $file);
        function_exists('opcache_invalidate') && @opcache_invalidate($file, TRUE);
        $check = @include $file;
        if (FALSE === $r || !is_array($check) || !hash_equals($newKey, (string)($check['auth_key'] ?? ''))) {
            return array('code' => 1, 'message' => '更换失败：写入 core/config/config.php 后回读不一致，auth_key 未变更');
        }
        // 当前请求内切到新密钥：派生新的 api 令牌、补登记（如启用了安装上报）都按新值计算
        $conf = (array)_SERVER('conf');
        $conf['auth_key'] = $newKey;
        if ('' !== $newStoreTok) $conf['store_report_token'] = $newStoreTok;
        class_exists('App', FALSE) and \App::set('conf', $conf);
        $_SERVER['conf'] = $conf;
        $GLOBALS['conf'] = $conf;
        $newApi = (string)api_wire_token('api');
        // 所有会话下线（前台 + 后台）：会话行本身不依赖 auth_key，需显式清空
        $s1 = db_truncate('session');
        $s2 = db_truncate('session_data');
        admin_token_clean();
        admin_audit_log('auth_key_rotate', array('uid' => $uid, 'session_cleared' => ($s1 && $s2) ? 1 : 0));
        $reg = '';
        if (!empty($conf['install_report']) && class_exists('\\App\\Service\\System\\CloudOpsService')) {
            $res = \App\Service\System\CloudOpsService::storeInstallRegister(FALSE);
            $reg = !empty($res['ok']) ? '<br>已向商店补登记新的采集令牌。' : '<br>商店补登记未完成（' . htmlspecialchars((string)($res['msg'] ?? ''), ENT_QUOTES, 'UTF-8') . '），云端采集首次失败时会自动补登记。';
        }
        $msg = 'auth_key 已更换。所有用户与管理员（包括你）都需要重新登录。'
            . '<br>新的 api 令牌（仅显示这一次）：<code>' . htmlspecialchars($newApi, ENT_QUOTES, 'UTF-8') . '</code>'
            . '<br>宝塔计划任务里的 api.php?token=… 采集/cron 地址需要换成新令牌（「采集 → 接口列表」里的定时采集链接已自动更新）。'
            . ((1 === intval($conf['api_legacy_token'] ?? 0)) ? '<br>注意：api_legacy_token=1，旧的「auth_key 原文当 token」用法同样已失效。' : '')
            . (($s1 && $s2) ? '' : '<br>会话表清空失败，已登录用户可能要到会话过期才下线。')
            . $reg;
        return array('code' => 0, 'message' => $msg);
    }

    private static function verifyToken(int $uid, string $scene): void
    {
        $safeToken = param('safe_token');
        admin_bull_token_guard($uid, $scene, $safeToken);
    }
}
