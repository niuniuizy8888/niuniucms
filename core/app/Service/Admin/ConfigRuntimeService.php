<?php
namespace App\Service\Admin;

class ConfigRuntimeService
{
    /**
     * 保存「站点设置」表单：双通道落库——基础键走 config_runtime_set_values()（KV 运行时层深合并，不改物理 config.php，§三.5），
     * website_mode/tpl_mode 合进 $config['setting'] 后 setting_set('conf')（⚠ 整档 REPLACE、非原子，并发保存会互相覆盖，见 kv.php setting_set 注释）。
     * 转义口径（VULN-002 存储型 XSS 修复，红线 §二.4「输出前上下文转义在入库处兜住」）：
     * sitename/area/lang/year = xn_html_safe+filter_all_html 后再 htmlspecialchars(ENT_QUOTES)（它们渲染进模板属性位）；
     * logo/tg/email 剥 <>"'` 与控制字符（URL/邮箱口径）；agg（站点统计代码）先 html_entity_decode 还原、再 xn_html_safe 白名单过滤。
     * 末尾写 admin_audit_log('set_save')。调用前提：路由层权限位 + admin_bull_token_guard 已过（本方法不做鉴权）。
     * @param array $payload 表单原始值（应已经 param() 默认转义链）
     * @param array $config  当前完整配置树（setting 节点在其上合并）
     * @return array ['success'=>true, 'msg'=>修改成功文案]
     */
    public static function saveWebsiteSettings(array $payload, array $config): array
    {
        // 上线前安全审计修复（存储型 XSS，VULN-002）：
        // sitename/area/lang/year 输出在模板属性上下文（<img alt="{$nncms.site_name}">、
        // <meta content="{$nncms.site_name}"/>、ld+json），filter_all_html/xn_html_safe 均不处理引号，
        // 与 saveSeo() 对 seo_* 的口径对齐，统一补 ENT_QUOTES；logo/tg/email 为 URL/邮箱，
        // 剥除引号/尖括号/反引号/控制字符（同 xn_url_strip 口径）。
        // agg 为站点统计代码（设计上原样输出），保持不动（模板当前未渲染，见审计报告）。
        $replace = array(
            'sitename' => htmlspecialchars(xn_html_safe(filter_all_html($payload['sitename'] ?? '')), ENT_QUOTES, 'UTF-8'),
            'logo' => preg_replace('/[\x00-\x1F\x7F<>"\'`]/', '', (string)($payload['logo'] ?? '')),
            'pagesize' => intval($payload['pagesize'] ?? 20),
            'postlist_pagesize' => intval($payload['postlist_pagesize'] ?? 20),
            'listsize' => intval($payload['listsize'] ?? 20),
            'linksize' => intval($payload['linksize'] ?? 20),
            'tagsize' => intval($payload['tagsize'] ?? 20),
            'tag_num' => intval($payload['tag_num'] ?? 5),
            'cdn_on' => intval($payload['cdn_on'] ?? 0),
            'api_on' => intval($payload['api_on'] ?? 0),
            'search_on' => intval($payload['search_on'] ?? 1),
            'sitemap_tag' => intval($payload['sitemap_tag'] ?? 0),
            'sitemap_play' => intval($payload['sitemap_play'] ?? 0),
            'sitemap_num' => intval($payload['sitemap_num'] ?? 100),
            'down_pic' => intval($payload['down_pic'] ?? 0),
            'area' => htmlspecialchars(xn_html_safe(filter_all_html($payload['area'] ?? '')), ENT_QUOTES, 'UTF-8'),
            'lang' => htmlspecialchars(xn_html_safe(filter_all_html($payload['lang'] ?? '')), ENT_QUOTES, 'UTF-8'),
            'year' => htmlspecialchars(xn_html_safe(filter_all_html($payload['year'] ?? '')), ENT_QUOTES, 'UTF-8'),
            'agg' => xn_html_safe(html_entity_decode($payload['agg'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'tg' => preg_replace('/[\x00-\x1F\x7F<>"\'`]/', '', (string)($payload['tg'] ?? '')),
            'email' => preg_replace('/[\x00-\x1F\x7F<>"\'`]/', '', (string)($payload['email'] ?? '')),
        );
        // ⚠ 写失败如实上报（审查修复）：旧实现无视 kv REPLACE 返回值，库写失败仍回「保存成功」。
        if (FALSE === config_runtime_set_values($replace)) {
            return array('success' => false, 'msg' => lang('save_failed'));
        }

        $setting = array_value($config, 'setting');
        // 首次保存时 config.php 可能无 setting 键（或为标量），兑底数组避免 Cannot use a scalar value as an array
        if (!is_array($setting)) $setting = array();
        $setting['website_mode'] = intval($payload['website_mode'] ?? 0);
        $setting['tpl_mode'] = intval($payload['tpl_mode'] ?? 0);
        $config['setting'] = $setting;
        setting_set('conf', $config);

        admin_audit_log('set_save', array());
        return array('success' => true, 'msg' => lang('modify_successfully'));
    }

    /**
     * 保存前台路由别名，写运行时键 'route'（config_runtime 深合并）。
     * ⚠ 契约修正（真机 fatal）：表单（core/admin/html/set_route.html）提交的是
     *   `data[路由名] = 别名` 的**扁平映射**（页面直接把 conf['route'] 当表单行渲染），
     *   而旧实现按「记录行列表」消费——`arrlist_key_values($rows,'id','rank')` 会对字符串值取下标，
     *   PHP 8.5 直接 `TypeError: Cannot access offset of type string on string`，且即便不炸，
     *   把 rank 值写进 conf['route']（语义是 URL 前缀）也会打穿 url() 拼链。现按真实形态重写。
     * 规则：
     *  - 只允许修改 conf['route'] 里**已存在**的键：表单塞入新键一律忽略（防任意键污染路由表）；
     *  - 别名过 xn_safe_word（仅留 \w，截 32 字符）；归一后为空 ⇒ 跳过该项＝保留原值
     *    （清空会让 url() 生成无前缀死链，故不提供"清除"能力）；
     *  - 未出现在提交里的键同样保留原值（深合并语义，不做删除）。
     * 空提交直接 message(1, data_is_empty) 输出中断（不返回）。审计日志 set_route（记实际变更的键名）。
     * @param array $rows 「路由名 => 别名」扁平映射（表单 data[] 原值，已由 param() 侧转义）
     * @return array ['success'=>true, 'msg'=>string] 本方法不返回 success=false：空入参已由 message() 中断，
     *         「全部值都非法/未变」属**无操作的成功保存**（不写库、回一般文案），不代表失败
     */
    public static function saveRoutes(array $rows): array
    {
        empty($rows) && message(1, lang('data_is_empty'));
        $conf = _SERVER('conf');
        $current = (isset($conf['route']) && is_array($conf['route'])) ? $conf['route'] : array();
        $route = $current;
        $changed = array();
        foreach ($rows as $name => $alias) {
            $name = (string)$name;
            if (!array_key_exists($name, $current)) continue;      // 白名单：只改既有路由键
            $alias = is_scalar($alias) ? xn_safe_word((string)$alias, 32) : '';
            if ('' === $alias) continue;                            // 空＝保留原值，不写死链
            if ((string)$route[$name] === $alias) continue;
            $route[$name] = $alias;
            $changed[] = $name;
        }
        if (empty($changed)) {
            return array('success' => true, 'msg' => lang('modify_successfully'));
        }
        // ⚠ 审查修复轮 C4：别名唯一性校验——两个路由共用同一前缀会让 URL 永久落第一个命中的
        // 路由处理器（$route_map 按键查找），保存时即拒绝；固定路由名/物理入口名保留，防
        // 别名与 search/sitemap/page 等发生前缀撞车。
        // 保留名（复核补全）：$route_map 字面键 + 物理入口 + 后台/插件分发前缀。
        // admin 此前缺失——虽然 alias 正则 ^[\w]*$ 不含 '.'、param(0)=='admin' 也会被改写成 index，
        // 但把 'admin' 占成某个列表别名仍会与后台入口路径语义冲突，直接禁止更安全。
        $reserved = array('index', 'search', 'sitemap', 'user', 'member', 'danmu', 'page', 'api', 'install', 'admin', 'plugin', '404');
        $seen = array();
        foreach ($route as $key => $alias) {
            if (!is_string($alias) || '' === $alias) continue;
            if (in_array($alias, $reserved, true)) {
                // 提示带键名（复核修正）：旧文案恒为「别名冲突」不带键，冲突存在期间任何路由保存都被拒，
                // 管理员不知道改哪个键 ⇒ 自解困难。
                return array('success' => false, 'msg' => lang('route_alias_conflict') . ' [' . $key . '=' . $alias . ']');
            }
            if (isset($seen[$alias])) {
                return array('success' => false, 'msg' => lang('route_alias_conflict') . ' [' . $key . '=' . $alias . ' ←→ ' . $seen[$alias] . ']');
            }
            $seen[$alias] = $key;
        }
        // ⚠ 写失败如实上报（审查修复）：KV 写失败不再回「保存成功」。
        if (FALSE === config_runtime_set_values(array('route' => $route))) {
            return array('success' => false, 'msg' => lang('save_failed'));
        }
        // 审查修复轮 C6：路由别名变化影响 url() 拼链 → 推进内容版本令牌，整页缓存即时失效
        // （否则旧 URL 的缓存页存活至 TTL 1800s，别名切换不生效）。
        function_exists('page_cache_bump') and page_cache_bump();
        admin_audit_log('set_route', array('keys' => $changed));
        return array('success' => true, 'msg' => lang('route_res'));
    }

    /**
     * 保存弹幕设置：整组 danmu 子键（开关 on、片头/暂停/结束广告及其时长与素材地址）深合并进运行时键 'danmu'。
     * 'cdn' 键已停用（§二.6 前端资源红线：前台固定用本站 /static/ 自托管播放器），仍写入仅兼容既有键位（缺省 1=本地资源）。
     * 审计日志 danmu_update（只记 on 状态）。
     * @param array $payload 表单原始值（数值字段逐个 intval；广告素材/地址字段原样入库）
     * @return array ['success'=>true, 'msg'=>修改成功文案]
     */
    public static function saveDanmu(array $payload): array
    {
        $replace = array();
        $replace['danmu'] = array(
            'on' => intval($payload['on'] ?? 0),
            // 'cdn' 键已停用：前台模板固定用本站 /static/ 资源，不再读该键。
            // 仍写入仅为兼容既有配置键位（缺省值取 1 = 原「本地资源」语义），新代码不要使用。
            'cdn' => intval($payload['cdn'] ?? 1),
            'start_ad' => intval($payload['start_ad'] ?? 0),
            'danmu_toggle' => intval($payload['danmu_toggle'] ?? 0),
            'pause_ad' => intval($payload['pause_ad'] ?? 0),
            'selec_col' => intval($payload['selec_col'] ?? 0),
            'start_ad_on' => intval($payload['start_ad_on'] ?? 0),
            'start_img_ad' => $payload['start_img_ad'] ?? '',
            'start_mp4_ad' => $payload['start_mp4_ad'] ?? '',
            'start_ad_url' => $payload['start_ad_url'] ?? '',
            'start_all_time' => intval($payload['start_all_time'] ?? 10),
            'start_close_time' => intval($payload['start_close_time'] ?? 5),
            'stop_ad' => $payload['stop_ad'] ?? '',
            'stop_ad_url' => $payload['stop_ad_url'] ?? '',
        );
        // ⚠ 写失败如实上报（审查修复）：KV 写失败不再回「保存成功」。
        if (FALSE === config_runtime_set_values($replace)) {
            return array('success' => false, 'msg' => lang('save_failed'));
        }
        admin_audit_log('danmu_update', array('on' => intval($payload['on'] ?? 0)));
        return array('success' => true, 'msg' => lang('modify_successfully'));
    }

    /**
     * 保存单页 SEO（标题/关键词/描述）到运行时键 seo[$page]：
     * $page 先过字符集白名单（M-A1——它做数组键并被 set_seo.html 裸 echo 进 id=/value= 属性位，引号可逃逸=后台存储型 XSS；
     * 清空后兜 'index'）；三个内容字段过滤后统一 htmlspecialchars(ENT_QUOTES) 入库（前台 <title>/meta 属性上下文，红线 §二.4）。
     * 写入方式：读 $conf['seo'] 整组 → 覆盖本页 → 整组 config_runtime_set_values（深合并回写）。审计日志 seo_update。
     * @param string $page     页面标识（白名单收口）
     * @param string $seo_title 标题
     * @param string $seo_key   关键词
     * @param string $seo_des   描述
     * @param array  $conf      当前配置树（取存量 seo 组）
     * @return array ['success'=>true, 'msg'=>修改成功文案]
     */
    public static function saveSeo(string $page, string $seo_title, string $seo_key, string $seo_des, array $conf): array
    {
        // 上线前审计修复（M-A1）：$page 被当数组键入库并经 set_seo.html 裸 echo 进 id=/value= 属性位
        // （admin_param_text 仅截断不转义），引号/尖括号可逃逸属性=后台存储型 XSS。字符集白名单收口。
        $page = preg_replace('#[^a-zA-Z0-9_\-]#', '', (string)$page);
        if ('' === $page) $page = 'index';
        $old = isset($conf['seo']) && is_array($conf['seo']) ? $conf['seo'] : array();
        // filter_all_html/xn_html_safe 均不处理引号，前台 <title>/meta content 属性上下文会逃逸——入库统一补 ENT_QUOTES
        $old[$page] = array(
            'seo_title' => htmlspecialchars(filter_all_html($seo_title), ENT_QUOTES, 'UTF-8'),
            'seo_des' => htmlspecialchars(xn_html_safe($seo_des), ENT_QUOTES, 'UTF-8'),
            'seo_key' => htmlspecialchars(filter_all_html($seo_key), ENT_QUOTES, 'UTF-8'),
        );
        // ⚠ 写失败如实上报（审查修复）：KV 写失败不再回「保存成功」。
        if (FALSE === config_runtime_set_values(array('seo' => $old))) {
            return array('success' => false, 'msg' => lang('save_failed'));
        }
        admin_audit_log('seo_update', array('page' => $page));
        return array('success' => true, 'msg' => lang('modify_successfully'));
    }

    /**
     * 保存 Elasticsearch 连接配置到运行时键 'es'（host/index/user/pass/api_key 整组深合并）。
     * fail-closed 格式闸：host 非空但不匹配「http(s):// + 主机合法字符集」正则时直接 success=false 拒存（不写库、不落审计）。
     * 内网 ES 属正常部署拓扑不拦私网地址，SSRF 纵深在 ElasticService 的 curl 层；审计日志 set_es_config 只记 host 不记凭据。
     * @param array $payload 表单值（host 将被 trim）
     * @return array ['success'=>bool, 'msg'=>string]——成功文案提示需另行手动「创建索引」
     */
    public static function saveEsConfig(array $payload): array
    {
        // ES 主机格式闸：仅 http/https + 合法主机字符（内网 ES 属正常部署拓扑不作内网拦截，SSRF 纵深在 ElasticService curl 层）
        $es_host = trim((string)($payload['host'] ?? ''));
        if ($es_host !== '' && !preg_match('#^https?://[A-Za-z0-9._\-:\[\]]+$#i', $es_host)) {
            return array('success' => false, 'msg' => 'ES 主机需为合法 http/https 地址');
        }
        $replace = array(
            'es' => array(
                'host' => $es_host,
                'index' => $payload['index'] ?? '',
                'user' => $payload['user'] ?? '',
                'pass' => $payload['pass'] ?? '',
                'api_key' => $payload['api_key'] ?? '',
            ),
        );
        // ⚠ 写失败如实上报（审查修复）：KV 写失败不再回「保存成功」。
        if (FALSE === config_runtime_set_values($replace)) {
            return array('success' => false, 'msg' => lang('save_failed'));
        }
        admin_audit_log('set_es_config', array('host' => $replace['es']['host']));
        return array('success' => true, 'msg' => '配置保存后请手动点击创建索引');
    }

    /**
     * ES 搜索总开关：config_runtime_set('es_on', $es_on) 单键写 + 审计日志 set_es_toggle。
     * 口径（§三.2）：es_on=0 时搜索回退前导通配 LIKE '%kw%'（必然全表扫，P-4 的 ngram FULLTEXT 是另一条 opt-in 路）；
     * 只改开关不建索引，前台会拿不到结果——返回文案里的「记得开启搜索功能」即指此。
     * @param int $es_on 1=启用 ES，0=关闭
     * @return array ['success'=>true, 'msg'=>提示文案]
     */
    public static function setEsSwitch(int $es_on): array
    {
        // ⚠ 写失败如实上报（审查修复）：KV 写失败不再回「保存成功」。
        if (FALSE === config_runtime_set('es_on', $es_on)) {
            return array('success' => false, 'msg' => lang('save_failed'));
        }
        admin_audit_log('set_es_toggle', array('es_on' => $es_on));
        return array('success' => true, 'msg' => '搜索将使用elasticsearch 记得开启搜索功能');
    }
}
