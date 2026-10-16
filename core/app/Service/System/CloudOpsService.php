<?php
namespace App\Service\System;

class CloudOpsService
{
    /**
     * 云端升级链统一错误日志：走 runtime/log 的 upgrade_error 通道，
     * 统一脱敏并受 10MB 自动覆盖策略约束。
     */
    private static function upgradeLogError(string $message): void
    {
        LoggerService::channel('upgrade_error', LoggerService::LEVEL_ERROR, $message);
    }

    /**
     * 商店服务端 base（`conf['cloud_api']`；空=未接商店）。
     * 与 `AddonService::storeBase()` 同口径，只在此处实现一次。
     */
    public static function storeBase()
    {
        $conf = _SERVER('conf');
        $u = trim(isset($conf['cloud_api']) ? (string)$conf['cloud_api'] : '');
        return '' === $u ? '' : rtrim($u, '/') . '/';
    }

    /** 当前请求站点 host（安装上报/补登记口径）；沿用安装器白名单规则，避免把脏 Host 带到商店侧。 */
    private static function reportHost(): string
    {
        $host = preg_replace('#[^A-Za-z0-9._:\-]#', '', (string)_SERVER('HTTP_HOST'));
        if ('' === $host) $host = preg_replace('#[^A-Za-z0-9._:\-]#', '', (string)_SERVER('SERVER_NAME'));
        return (string)$host;
    }

    /** 当前请求站点 scheme（优先显式 REQUEST_SCHEME / X-Forwarded-Proto，退回 HTTPS/端口判定）。 */
    private static function reportScheme(): string
    {
        $scheme = strtolower(trim((string)_SERVER('REQUEST_SCHEME')));
        if ('http' === $scheme || 'https' === $scheme) return $scheme;
        $proto = strtolower(trim((string)_SERVER('HTTP_X_FORWARDED_PROTO')));
        if ('http' === $proto || 'https' === $proto) return $proto;
        $https = strtolower(trim((string)_SERVER('HTTPS')));
        if ('' !== $https && 'off' !== $https && '0' !== $https) return 'https';
        return 443 === intval(_SERVER('SERVER_PORT')) ? 'https' : 'http';
    }

    /**
     * 向商店补登记当前站点的异步采集 token。
     *
     * 用途：安装上报漏掉 / 历史 auth_key 变更后 token 漂移时，云端异步采集与替换下发会收到
     * 「无效 token 或站点未启用」；此处按**当前运行配置**重发一份登记并由调用方重试一次请求，
     * 让站点自愈，不要求站长重装或手工改库。
     */
    public static function storeInstallRegister(bool $force = FALSE): array
    {
        $store = self::storeBase();
        if ('' === $store) return array('ok' => FALSE, 'msg' => '未配置商店地址（config: cloud_api），无法补登记 token');
        $conf = _SERVER('conf');
        if (!$force && empty($conf['install_report'])) {
            return array('ok' => FALSE, 'msg' => '安装时未启用安装上报，当前未自动补登记');
        }
        $token = function_exists('api_wire_token') ? (string)api_wire_token('api') : '';
        if ('' === $token) return array('ok' => FALSE, 'msg' => '本站缺少采集令牌（auth_key 未配置），无法补登记 token');
        $host = self::reportHost();
        if ('' === $host) return array('ok' => FALSE, 'msg' => '取不到本站域名，无法补登记 token');
        $payload = array(
            'token' => $token,
            'host' => $host,
            'domain' => self::reportScheme() . '://' . $host,
            'serverip' => (string)_SERVER('SERVER_ADDR'),
            'cms_version' => isset($conf['version']) ? (string)$conf['version'] : '',
            'php_version' => PHP_VERSION,
            'sitename' => isset($conf['sitename']) ? (string)$conf['sitename'] : '',
        );
        $raw = https_request($store . 'index.php?api=install', $payload, '', 8, 0, 8 * 1048576);
        if (FALSE === $raw) {
            if (function_exists('xn_log')) xn_log('store install register failed: no response', 'cloud');
            return array('ok' => FALSE, 'msg' => '商店补登记 token 失败（无响应）');
        }
        $res = xn_json_decode((string)$raw);
        if (!is_array($res) || 0 !== intval($res['code'] ?? -1)) {
            $err = is_array($res) ? (string)($res['msg'] ?? '') : '返回非 JSON';
            if (function_exists('xn_log')) xn_log('store install register rejected: ' . $err, 'cloud');
            return array('ok' => FALSE, 'msg' => '商店补登记 token 失败：' . $err);
        }
        return array('ok' => TRUE, 'msg' => 'ok');
    }

    /**
     * 云端 code 数据（`cloud_version` / `update_log` / `rsync` / `down_path` / `api` / `list` / `tmpl` / `artapi`）。
     *
     * 走 `{base}api.php` —— API.md §0.1 定义的「CMS 云端 code 抓取入口」，
     * 顶层 JSON、**无 `{code,data}` 壳、无需也不得 `core_decrypt`**（商店不加密）。
     *
     * **已彻底移除 legacy 回退**：旧 `get_code()`（`conf['auth_salt']` +
     * 内置密文 + `core_decrypt`）与旧官方域名（`poster.example.com` / 旧官方云端 `example.com`）**一律不再使用**；
     * 现域名统一来自配置项 `cloud_host`（派生 `cloud_api`）。
     * 未配 `cloud_api` 时**直接返回 null 并留痕**——云功能整体不可用是**显式**的，
     * 而不是静默回落到一个已停用的旧云端（静默回落会让"配置漏了"伪装成"功能正常"）。
     *
     * @param bool $cacheOnly TRUE 时只读缓存绝不发请求（后台入口引导期强制走此路，见 admin.php 注释）
     * @return array|null
     */
    public static function codes($cacheOnly = false)
    {
        $store = self::storeBase();
        if ('' === $store) {
            self::logNoStore('codes');
            return null;
        }
        $raw = cache_get('code');
        if (empty($raw) && !$cacheOnly) {
            $raw = self::storeFetch($store . 'api.php');
        }
        if (empty($raw)) return null;
        $arr = xn_json_decode($raw);
        return is_array($arr) ? $arr : null;
    }

    /**
     * 「未配置 cloud_api」留痕（低频、进程内去重，避免每请求刷日志）。
     */
    private static function logNoStore($where)
    {
        static $logged = FALSE;
        if ($logged) return;
        $logged = TRUE;
        if (function_exists('xn_log')) {
            try { xn_log('cloud_api 未配置，云端功能不可用（legacy 旧云端已于 停用）: ' . $where, 'cloud'); } catch (\Throwable $e) {}
        }
    }

    /**
     * 云端（商店）版本号读取：优先只读 `code` 缓存（cacheOnly，不在渲染/引导期触发网络）；
     * 缓存缺失且 cloud_api 已配置时才回源 storeFetch 一次。cloud_api 未配置恒返 null（留痕 cloud 日志）。
     * 需要「当下真实版本」（如升级页）请用 cloudVersionFresh()——本方法可能返回 600s 内的旧值。
     * @return string|null 版本号（形如 1.0.3，键 cloud_version）；取不到返回 null 由调用方兜底
     */
    public static function cloudVersion()
    {
        $res = self::codes(TRUE);
        if (!is_array($res) && '' !== self::storeBase()) {
            $res = self::codes(FALSE);
        }
        return is_array($res) ? ($res['cloud_version'] ?? null) : null;
    }

    /**
     * 强制向商店确认当下云版本（**忽略 600s 的 `code` 缓存**），供后台「系统升级」页使用。
     *
     * 为什么需要它：后台入口 `admin.php` 按 F-01 加固只读缓存（禁止引导期出网，必须保留），
     * 而 `code` 键 TTL 600s 且**后台路径上没有任何地方刷新它** ⇒ 升级页读到的可能是十分钟前的旧版本；
     * 缓存冷时 `admin.func.php` 更会直接把**本站当前版本**当成云端版本。
     * 症状：升级成功后版本号不前进、后台永远提示可升级（真机实测）。
     * 商店不可达 ⇒ 回落缓存值（不改变既有可用性，也不新增失败模式）。
     *
     * @return string|null 形如 1.0.3；取不到返回 null 由调用方兜底
     */
    public static function cloudVersionFresh()
    {
        if ('' === self::storeBase()) return null;
        $cached = self::codes(TRUE);
        cache_delete('code');
        $fresh = self::codes(FALSE);
        $res = is_array($fresh) ? $fresh : $cached;
        $v = is_array($res) ? ($res['cloud_version'] ?? null) : null;
        return (is_string($v) && '' !== $v) ? $v : null;
    }

    /**
     * 牛牛助手数据（`?macapi`，API.md §0.3，顶层 14 键同序）——取 `{base}?macapi`。
     * 旧海报接口 `https://example.com/?macapi` 已于 停用，无任何回退。
     */
    public static function niuniuzsCodes($cacheOnly = false)
    {
        $apistr = cache_get('niuniuzs_code');
        if (empty($apistr) && !$cacheOnly) {
            $apistr = self::niuniuzsGetCode();
        }
        if (empty($apistr)) {
            return null;
        }
        return xn_json_decode($apistr);
    }

    /**
     * 实时拉取牛牛助手数据（`{cloud_api}?macapi`）并维护 1800s 缓存；
     * 失败/空响应写 60s 退避标记后回落旧缓存（退避窗口内本方法直接返回缓存，不再出网）。
     * cloud_api 未配置 ⇒ 返回空串（fail-closed，不发往旧云端）。超时收紧为 8s。
     * @return string|null 原始 JSON 串；失败且无缓存时可回落到 cache_get 的值（含 NULL）
     */
    public static function niuniuzsGetCode()
    {
        $store = self::storeBase();
        if ('' === $store) {
            self::logNoStore('niuniuzsGetCode');
            return '';
        }
        // ：①超时由默认 30s 收紧到 8s；②失败后 60s 退避。
        // 背景：后台「替换信息」在**已登录**请求上会补一次刷新（见 admin.func.php），
        // 若商店不可达，原实现每个后台页都要同步白等 30s 且每次重试；退避后只在窗口外重试。
        if (cache_get('niuniuzs_code_backoff')) {
            return (string)cache_get('niuniuzs_code');
        }
        $res = https_request($store . '?macapi', '', '', 8, 0, 8 * 1048576);
        if ('' === trim((string)$res)) {
            cache_set('niuniuzs_code_backoff', 1, 60);
            $res = cache_get('niuniuzs_code');
        } else {
            cache_set('niuniuzs_code', $res, 1800);
        }
        return $res;
    }

    /**
     * 商店端点取数（600s 缓存，与 `AddonService::storePlugins()` 同节奏）。
     * 失败返回空串且**不写缓存**（保持 legacy 的"失败不缓存"语义，避免把一次超时固化 10 分钟）。
     */
    private static function storeFetch($url)
    {
        $res = https_request($url, '', '', 15, 0, 8 * 1048576);
        if (!is_string($res) || '' === trim($res)) return '';
        if (is_array(xn_json_decode($res))) {
            cache_set('code', $res, 600);
        }
        return $res;
    }

    /**
     * 读商店最近 30 天替换记录（`{base}index.php?api=replacements`，只读）。
     *
     * 用途：① 后台「查看商店最近 30 天替换记录」页 ② **局域网/内网部署的"拉取应用"**
     * （商店的推送通道 `push-replacements` 按 M4 审查**无条件拒绝私网目标**——push 会带安装令牌，
     * 属刻意收紧，Go 侧还有用例锁定；故商店与站点同处内网时，改由站点**主动拉取**并本地应用）。
     *
     * @return array [行数组, 错误文案]（错误为空串即成功；失败返回空数组，不抛异常）
     */
    public static function storeReplacements(): array
    {
        $store = self::storeBase();
        if ('' === $store) return array(array(), '未配置商店地址（config: cloud_api）');
        $arr = xn_json_decode((string)https_request($store . 'index.php?api=replacements', '', '', 20, 0, 8 * 1048576));
        if (!is_array($arr) || !isset($arr['data']['list']) || !is_array($arr['data']['list'])) {
            return array(array(), '商店无响应或返回格式异常');
        }
        return array($arr['data']['list'], '');
    }

    /**
     * 提交一条**域名替换**给商店（新增，后台「设置→地址替换」的「执行替换」按钮）。
     *
     * 契约：`POST {cloud_api}index.php?api=replace` + `token`（= 本站安装时上报的 wire 令牌，
     * 商店按 `installs.token` 且 status=1 校验）+ source_id/code + type(play|pic) +
     * old_domain/new_domain（纯域名，可带端口）+ remark + push(默认 1：登记后商店立即通知
     * rsync-task worker 全站推送；=0 只登记)。商店侧同一张 `domain_replacements` 表、同一套
     * 域名格式闸（与商店后台 `?c=replacements` 的 op=save 等价），两条路都能被 worker 推送消费。
     *
     * 出站仍全程过 `outbound_url_safe`（走 https_request），商店主机在可信豁免名单内。
     */
    public static function storeReplaceSubmit(array $payload): array
    {
        $store = self::storeBase();
        if ('' === $store) return array('ok' => FALSE, 'msg' => '未配置商店地址（config: cloud_api），无法下发替换');
        $token = function_exists('api_wire_token') ? (string)api_wire_token('api') : '';
        if ('' === $token) return array('ok' => FALSE, 'msg' => '本站缺少采集令牌（auth_key 未配置），无法下发替换');
        $payload['token'] = $token;
        $url = $store . 'index.php?api=replace';
        $raw = https_request($url, $payload, '', 20, 0, 8 * 1048576);
        $arr = xn_json_decode((string)$raw);
        if (!is_array($arr)) {
            self::logNoStore('replace');
            return array('ok' => FALSE, 'msg' => '商店无响应或返回非 JSON（' . $url . '）');
        }
        $msg = trim((string)($arr['msg'] ?? ''));
        if ('' !== $msg && preg_match('#无效 token|站点未启用#u', $msg)) {
            $repair = self::storeInstallRegister();
            if (!empty($repair['ok'])) {
                $raw = https_request($url, $payload, '', 20, 0, 8 * 1048576);
                $arr = xn_json_decode((string)$raw);
                if (!is_array($arr)) {
                    self::logNoStore('replace-retry');
                    return array('ok' => FALSE, 'msg' => '商店补登记后重试替换失败（返回非 JSON）');
                }
            }
        }
        return array(
            'ok'   => (0 === intval($arr['code'] ?? -1)),
            'msg'  => (string)($arr['msg'] ?? ''),
            'data' => (isset($arr['data']) && is_array($arr['data'])) ? $arr['data'] : array(),
        );
    }

    /**
     * 读云端 code 载荷（**统一入口，勿再各自 `cache_get('code') + core_decrypt`**）。
     *
     * 商店服务端**完全不加密**（`API.md` 无任何加密概念、商店代码里没有解密函数），
     * 其 `api.php` 是明文顶层 JSON；只有 legacy 官方云端才走「密文 + `core_decrypt`」。
     * 故按模式分流：store 直接 `json_decode`（**一次解密都不做**）；legacy 先解密再解析。
     *
     * @param bool $cacheOnly TRUE 时只读缓存绝不发请求
     * @return array 解析后的数组；不可用时为空数组
     */
    public static function cloudPayload($cacheOnly = false)
    {
        $res = self::codes($cacheOnly);
        return is_array($res) ? $res : array();
    }

    /**
     * 商店**自陈的 `site_url` 主机**（`?api=site` → `data.site_url`）。
     *
     * 用途：商店用它自己的 `site_url` 拼所有绝对 URL（商店侧 `model/cloud.php::cloud_site_url()`），
     * 而该值与 CMS 的 `cloud_api` **可以不同**（本站实况：商店写 `192.0.2.3:2651`、
     * CMS 配 `127.0.0.1:2651`）。要正确归一，参照系必须是**商店自陈的地址**，而不是拿
     * `cloud_api` 的 host 去猜——后者在两者不一致时必然失配（实测过）。
     *
     * 缓存 600s；取不到返回空串（`storeUrl()` 随即退化为不改写，行为与不归一一致）。
     */
    public static function storeSiteHost()
    {
        static $host = NULL;
        if (NULL !== $host) return $host;
        $host = '';
        $cached = cache_get('store_site_host');
        if (is_string($cached)) { $host = $cached; return $host; }
        $store = self::storeBase();
        if ('' === $store) return $host;
        $arr = xn_json_decode((string)https_request($store . '?api=site', '', '', 10, 0, 8 * 1048576));
        $u = (is_array($arr) && isset($arr['data']['site_url'])) ? (string)$arr['data']['site_url'] : '';
        $h = strtolower((string)parse_url($u, PHP_URL_HOST));
        if ('' !== $h) {
            $host = $h;
            cache_set('store_site_host', $h, 600);
        }
        return $host;
    }

    /**
     * 商店生成的**绝对 URL 归一为对客户端可达的地址**。
     *
     * 商店把自身配置的 `site_url` 写进它生成的绝对 URL（`api.php` 的 `tmpl.*.pic`/`downurl`、
     * `rsync`、`down_path`，`?api=plugin|template` 的图标与 `download_url`）。当商店 `site_url`
     * 与 CMS 的 `cloud_api` 不同（本站实况：商店写 `192.0.2.3:2651`，CMS 配 `127.0.0.1:2651`），
     * 客户端拿到的就是**不可达地址**；且该主机不在 `outbound_trusted_hosts()` 豁免名单内，
     * 会被 `outbound_url_safe()` 直接拦下，表现为"下载失败"而无明显线索。
     *
     * 处置：URL 主机**恰为商店主机**（`cloud_api` 的 host）时，只取 path+query+fragment 拼回
     * `cloud_api` 基址——仍走商店、路径不变，但落到可达地址上。非商店主机的绝对 URL 与
     * 相对路径一律原样返回（**不越权改写**）。
     *
     * @param string $url 云端给出的 URL
     * @return string 可用于请求的 URL；无法处理时原样返回
     */
    public static function storeUrl($url)
    {
        if (!is_string($url)) return '';
        $url = trim($url);
        if ('' === $url) return '';
        $store = self::storeBase();
        if ('' === $store) return $url;
        if (!preg_match('#^https?://#i', $url)) return $url;
        $uHost = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ('' === $uHost) return $url;
        // 参照系：商店自陈 site_url 的 host；取不到时退回 cloud_api 的 host
        $refs = array_filter(array(
            self::storeSiteHost(),
            strtolower((string)parse_url($store, PHP_URL_HOST)),
        ), function ($h) { return '' !== $h; });
        if (!in_array($uHost, $refs, TRUE)) return $url;   // 非商店主机 → 不越权改写
        $path = (string)parse_url($url, PHP_URL_PATH);
        $q = parse_url($url, PHP_URL_QUERY);
        $f = parse_url($url, PHP_URL_FRAGMENT);
        return rtrim($store, '/') . $path
            . (is_string($q) && '' !== $q ? '?' . $q : '')
            . (is_string($f) && '' !== $f ? '#' . $f : '');
    }

    /**
     * 云端返回的资源地址归一（配合 store 模式）。
     *
     * **为什么要它**：legacy 云端把这些字段以**密文**下发（`hb`/`rsync`/`down_path` 均需
     * `core_decrypt()` 还原）；而 商店服务端**完全不加密**，其 `api.php` 下发的是**明文地址**——
     * ** `hb` 为绝对 URL**（实测 `http://api.example.com/?hb`；旧版是站内相对 `/?hb`），
     * `rsync`/`down_path` 同为绝对 URL。实测 `core_decrypt('/?hb')` 返回**空串**
     * （明文本就不是合法 base64+AES 载荷）——若不做分支，store 模式下
     * `VodEditService::posterSync()` 会静默失效、海报一张也同步不到。
     *
     * 口径：**先归一可达地址、绝不解密**（商店不加密）。绝对 http(s) 原样放行、站内相对路径补商店基址、
     * 商店自陈主机的绝对 URL 改写为 `cloud_api` 基址；其余（空值/非法 scheme）返回空串。
     * 旧 legacy 的 `core_decrypt` 分支已于 移除。
     *
     * @param mixed $value 云端字段原值
     * @return string 可用地址；不可用时为空串
     */
    public static function cloudUrl($value)
    {
        if (!is_string($value)) return '';
        $v = trim($value);
        if ('' === $v) return '';
        // 修复（海报更新恒 0 条）：商店曾以**站内相对路径** `"/?hb"` 下发（API.md §0.1 旧例），
        // 旧实现原样返回 ⇒ 调用方拿 `/?hb` 去 https_request（无 host）必然失败。现商店下发绝对 URL，
        // 但**两种形态都必须兼容**（老商店/缓存里的旧值）：站内相对路径一律补**商店基址**
        // （绝不当作本站路径——那是商店的路径）。
        if (str_starts_with($v, '/') && 0 !== strpos($v, '//')) {
            $store = self::storeBase();
            if ('' === $store) return '';
            $v = rtrim($store, '/') . $v;
        }
        $v = self::storeUrl($v);
        return (preg_match('#^https?://#i', $v) || str_starts_with($v, '/')) ? $v : '';
    }

    /**
     * 下载并应用云端升级包（整站覆盖，高危写路径）。链路与防线：
     * ① $url 非 http(s) 形态先试 core_decrypt 还原（兼容商店旧密文下发），仍不合法拒绝；
     * ② 主机白名单纵深 = 仅 cloud_api 主机及其子域（302 逐跳复查不变）；
     * ③ http_download_to_file 流式下载（200MB 上限、PK 魔数校验）落 runtime/cache/<版本>.zip；
     * ④ zip 闸：条目 ≤5000、validateUpgradeZip、总解压体积 ≤1GB、符号链接条目拒收；
     * ⑤ 解压临时目录 → 复制回站点根；记录版本以包内自陈为准（与传入不一致时按包内值并写 upgrade_error 日志）。
     * 任一闸失败即返回 FALSE 并清理已下载的包。
     * @param string $url 商店下发的包地址（明文或 core_encrypt 密文）
     * @param string $cloud_version 商店下发版本号（仅用作临时路径命名，最终记录值取包内版本）
     * @return bool TRUE=下载校验并覆盖成功；FALSE=任一环节拒绝（error_log 留痕）
     */
    public static function updateDown($url, $cloud_version)
    {
        $conf = _SERVER('conf');         global $config;
        if (!class_exists('ZipArchive')) {
            self::upgradeLogError('update_down error: PHP zip extension not loaded (ZipArchive missing)');
            return false;
        }
        if (!is_string($url) || $url === '') {
            self::upgradeLogError('update_down error: invalid download url payload');
            return false;
        }
        if (!preg_match('#^https?://#i', $url)) {
            $decoded_url = core_decrypt($url);
            if (is_string($decoded_url) && preg_match('#^https?://#i', $decoded_url)) {
                $url = $decoded_url;
            }
        }
        if (!preg_match('#^https?://#i', $url)) {
            self::upgradeLogError('update_down error: unsupported download url');
            return false;
        }
        // 域名白名单纵深防御（上线前加固；收敛为**单一来源**）：
        // 升级包**只允许 `cloud_api` 主机**（含其子域）。官方 niuniuzs/niuniucms 域名已按裁定
        // 彻底退出（`cloud_api` 为唯一源），不再保留兼容白名单。
        // 防的是「cloud_api 响应被篡改后指向任意地址」；SSRF 守卫（scheme+DNS+内网段）与
        // ssl_verify 为第一道防线，**逐跳重定向复查保持不变**（302 到非白名单主机仍被拒）。
        $cloudHosts = array();
        $storeHost = strtolower((string)parse_url(self::storeBase(), PHP_URL_HOST));
        if ('' !== $storeHost) $cloudHosts[] = $storeHost;
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $hostAllowed = false;
        foreach ($cloudHosts as $cloudHost) {
            if ($host === $cloudHost || substr($host, -strlen('.' . $cloudHost)) === '.' . $cloudHost) {
                $hostAllowed = true;
                break;
            }
        }
        if ('' === $host || !$hostAllowed) {
            self::upgradeLogError('update_down error: download host not allowed');
            return false;
        }
        $cloud_version = basename($cloud_version);
        $save_path = APP_PATH . "runtime/cache/" . $cloud_version . ".zip";
        // 代码审查 #1 修复：流式下载（原 https_request 整包入内存后才 strlen 判 200MB ⇒
        // 上限在内存维度无效；且 fwrite(fopen()) 无错误检查会静默留空包）。
        // 现在传输过程中按块累计、超限即中止；写临时文件 + 成功后 rename。
        // 包体下限 4 字节（zip 最小长度）与 PK 前缀一并在此校验，替代原 strlen($zip)<4。
        $dl = http_download_to_file($url, $save_path, 200 * 1048576, 60, 5, 'PK');
        if (!$dl['ok'] || $dl['bytes'] < 4) {
            self::upgradeLogError('update_down error: download failed (' . $dl['error'] . ', ' . $dl['bytes'] . 'B)');
            return false;
        }
        $destpath = APP_PATH;
        $zip = new \ZipArchive();
        if ($zip->open($save_path) !== TRUE) {
            // 发布前检查修复（B-6）：失败路径清理已下载的升级包，防 runtime/cache 残留 zip
            @unlink($save_path);
            self::upgradeLogError('ZipArchive error: cannot open ' . $save_path);
            return false;
        }
        // 条目数限制（防 zip 炸弹）
        $zipNumFiles = (int)$zip->numFiles;
        if ($zipNumFiles > 5000) {
            $zip->close();
            @unlink($save_path);
            self::upgradeLogError('ZipArchive error: too many entries');
            return false;
        }
        if (!self::validateUpgradeZip($zip)) {
            $zip->close();
            @unlink($save_path);
            return false;
        }
        // 版本号以**包内自陈**为准（`core/config/config.default.php` 的 version；构建脚本保证它与
        // 商店 `cloud_version` 同源同值）。为什么必须这样取：
        //   · 调用方传入的 `$cloud_version` 来自后台引导期的 `code` 缓存（`admin.php` 按 F-01 只读缓存，
        //     该键 TTL 600s 且后台路径无人刷新）；缓存冷时 `admin.func.php` 直接回落到**本站当前版本**。
        //   · 旧实现把这个值原样写回线上 `config.php` ⇒ **升级后版本号不前进**：后台永远显示可升级，
        //     每点一次都重新下载整包覆盖（真机实测：商店 1.0.3、升级返回成功、站点仍记 1.0.2）。
        //   · 包内值 = 实际落盘代码的版本，不依赖网络与缓存，是这条链上唯一的权威源。
        $pkgVer = self::packageVersionFromZip($save_path);
        if ('' !== $pkgVer && $pkgVer !== $cloud_version) {
            // 不改下载/解压路径（$save_path 与 $tmpdir 已按传入值建好），只纠正"记录哪个版本"
            xn_log('升级包版本与商店下发版本不一致：包内 ' . $pkgVer . ' / 传入 ' . $cloud_version . '（按包内为准记录）', 'upgrade_error');
            $cloud_version = $pkgVer;
        }
        // 解压前逐条目校验：总解压体积上限 1GB + 符号链接条目直接拒绝（审计修复）
        // 顺带收集**文件条目名**：句柄 close() 之后 statIndex() 在 PHP 8 会抛 ValueError，
        // 故"覆盖前可写性预检"必须复用这里收集的清单（实测踩到：预检写在 close 之后 ⇒ 升级直接 fatal）
        $totalSize = 0;
        $zipFileEntries = array();
        for ($i = 0; $i < $zipNumFiles; $i++) {
            $st = $zip->statIndex($i);
            if ($st === false) continue;
            if ('' !== (string)$st['name'] && '/' !== substr((string)$st['name'], -1)) $zipFileEntries[] = (string)$st['name'];
            $totalSize += intval($st['size']);
            if ($totalSize > 1024 * 1048576) {
                $zip->close();
                @unlink($save_path);
                self::upgradeLogError('ZipArchive error: total uncompressed size exceeds limit');
                return false;
            }
            $opsys = 0;
            $attr = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && ($opsys & 0xFF) === 3 && (($attr >> 16) & 0xF000) === 0xA000) {
                $zip->close();
                @unlink($save_path);
                self::upgradeLogError('ZipArchive error: symlink entry rejected: ' . $st['name']);
                return false;
            }
        }
        // 解压到临时目录 → 删除符号链接条目（防 symlink 逃逸写任意路径）→ 再复制回站点根
        $tmpdir = APP_PATH . "runtime/cache/upgrade_tmp_" . $cloud_version . "/";
        rmdir_recusive($tmpdir, 1);
        @mkdir($tmpdir, 0777, true);
        if (!is_dir($tmpdir)) {
            $zip->close();
            @unlink($save_path);
            self::upgradeLogError('ZipArchive error: cannot create tmp dir');
            return false;
        }
        $zip->extractTo($tmpdir);
        $zip->close();
        // 扫描解压产物，删除符号链接（文件与目录）——发布前检查修复（B-6）：
        // glob 不匹配点前缀条目（.x 软链可存活到 copy_recusive 被跟随复制），
        // 改 scandir 栈式遍历（含点条目；is_link 先于 is_dir 判定，不跟链下钻）。
        $scanStack = array($tmpdir);
        while ($scanStack) {
            $scanDir = array_pop($scanStack);
            $scanEntries = @scandir($scanDir);
            if (!is_array($scanEntries)) continue;
            foreach ($scanEntries as $scanEntry) {
                if ('.' === $scanEntry || '..' === $scanEntry) continue;
                $scanPath = $scanDir . $scanEntry;
                if (is_link($scanPath)) { @unlink($scanPath); continue; }
                if (is_dir($scanPath)) $scanStack[] = $scanPath . '/';
            }
        }
        // 升级包是「出厂态」（内置插件 conf.json 一律 installed=0/enable=0），而本方法是
        // 整包覆盖 APP_PATH —— 不做保留会把线上已启用插件静默关停（发布打包轮）。
        // 快照/回填只针对**升级前已存在的插件目录**：包内新增插件不在快照内，保持包内出厂态，
        // 不会出现"表还没建就被启用"。与 AddonService::installFromUpload 的 $keepFlags=TRUE 同一约定。
        // 升级完整性闸（真机实测教训）：站点若混有**非 www 属主**的旧文件（root 上传/
        // root 解包，实测测试站 501 个 644 root:root），旧实现的 copy() 会**静默跳过**这些文件 ⇒
        // 升级返回成功、后台显示已升级，实际文件还是旧的（"升级假成功"）。现两道闸：
        //   ① 覆盖前预检：zip 内条目若在站内已存在且**父目录也不可写**，直接列出并中止（不动站点）；
        //   ② 覆盖时用 $copyErrors 收集真正落盘失败的路径，非空 ⇒ 记日志并**返回 false**。
        $blocked = array();
        foreach ($zipFileEntries as $name) {
            // 条目名已在 validateUpgradeZip 内做过归属/穿越校验，这里仅判断落盘可行性
            $target = APP_PATH . $name;
            if (is_file($target)) {
                if (!is_writable($target) && !is_writable(dirname($target))) $blocked[] = $name;
            } elseif (is_dir(dirname($target)) && !is_writable(dirname($target))) {
                $blocked[] = $name . '（目录不可写）';
            }
            if (count($blocked) >= 20) break;
        }
        if ($blocked) {
            // 注意：zip 句柄在解压后已 close()（此处再 close 会在 PHP 8 抛 ValueError —— 实测踩到）
            @unlink($save_path);
            rmdir_recusive($tmpdir, 1);
            xn_log('云端升级中止：站点内有 ' . count($blocked) . ' 个路径不可写（属主/权限），未做任何覆盖。示例：'
                . implode('、', array_slice($blocked, 0, 10))
                . ' —— 修复：chown -R www:www 站点根目录后重试', 'upgrade_error');
            self::upgradeLogError('update_down aborted: unwritable paths, e.g. ' . implode(', ', array_slice($blocked, 0, 5)));
            return false;
        }

        $pluginState = self::snapshotPluginState();
        $copyErrors = array();
        copy_recusive($tmpdir, APP_PATH, $copyErrors);
        rmdir_recusive($tmpdir, 1);
        // 关键：即使覆盖有失败，插件态回填与入口同步也必须照常执行（否则失败会顺带把插件关停）
        self::restorePluginState($pluginState);
        if ($copyErrors) {
            self::syncAdminEntry();
            xn_log('云端升级不完整：' . count($copyErrors) . ' 个文件未能覆盖（属主/权限）。示例：'
                . implode('、', array_slice($copyErrors, 0, 10))
                . ' —— 修复：chown -R www:www 站点根目录后重新升级', 'upgrade_error');
            self::upgradeLogError('update_down incomplete: ' . count($copyErrors) . ' files not copied');
            return false;
        }
        // 后台入口同理：出厂包只带 admin.php，线上入口在安装时已随机改名
        // ⇒ 整包覆盖既升级不到线上入口文件（它不在包里），又会把众所周知的 admin.php 写回根目录
        self::syncAdminEntry();
        if (is_file($save_path)) unlink($save_path);
        // 版本号属启动期配置：入口与云版本展示在 KV 注入前即读取，保留物理写 config/config.php
        $replace = array();
        $replace['version'] = $cloud_version;
        file_replace_var(APP_PATH . 'core/config/config.php', $replace);
        $config['name'] = 'NiuNIUCms';
        $config['version'] = $cloud_version;
        $config['official_version'] = $cloud_version;
        $config['version_date'] = time();
        $config['upgrade'] = 0;
        setting_set('conf', $config);
        $upgradefile = APP_PATH . 'runtime/upgrade_sql.php';
        if (is_file($upgradefile)) include _include($upgradefile);
        // 精确清编译产物（对齐「不要清掉熔断/锁状态」的硬性规定，见 AddonService::clearTmpDir 注释）
        \App\Service\System\AddonService::clearTmpDir($conf['cache_path']);
        return TRUE;
    }

    /**
     * 升级包条目路径安全校验（M1 加固）
     * 规范化后逐段检查：拒绝 `..` 段 / 绝对路径 / Windows 盘符 / 空名（防路径穿越逃逸站点根）
     * @param \ZipArchive $zip 已打开的 zip
     * @return bool true=全部条目安全
     */
    public static function validateUpgradeZip(\ZipArchive $zip)
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            $norm = str_replace('\\', '/', $entry);
            // 拒绝绝对路径（前导 / 或 Windows 盘符）与空名——Zip 条目必须为相对路径
            if ($norm === '' || $norm[0] === '/' || str_contains($norm, ':')) {
                self::upgradeLogError('ZipArchive error: illegal path in entry ' . $entry);
                return false;
            }
            foreach (explode('/', $norm) as $seg) {
                if ($seg === '..' || $seg === '.') {
                    self::upgradeLogError('ZipArchive error: illegal path in entry ' . $entry);
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * 升级前快照「本机插件运行态」（发布打包轮）。
     *
     * 背景：出厂安装包把 5 个内置插件的 conf.json 一律归零（installed=0/enable=0），
     * 而 updateDown() 是整包覆盖 APP_PATH ⇒ 直接覆盖会把线上已启用插件静默关停
     * （启用状态是"本机运行态"，不是包内出厂态，升级不得改写）。
     * 约定与 AddonService::installFromUpload 的 $keepFlags=TRUE（商店更新保留启用状态）一致。
     *
     * @param string|null $base 站点根（默认 APP_PATH；仅供离线/沙箱场景注入，必须以 / 结尾）
     * @return array $_dir => array('installed'=>0|1, 'enable'=>0|1)
     */
    public static function snapshotPluginState($base = NULL)
    {
        $state = array();
        $base = (NULL === $base) ? APP_PATH : rtrim($base, '/\\') . '/';
        $paths = glob($base . 'plugins/*', GLOB_ONLYDIR);
        if (!is_array($paths)) return $state;
        foreach ($paths as $path) {
            $conffile = $path . '/conf.json';
            if (!is_file($conffile)) continue;
            $arr = xn_json_decode(file_get_contents($conffile));
            if (!is_array($arr)) continue;
            $state[basename($path)] = array(
                'installed' => !empty($arr['installed']) ? 1 : 0,
                'enable'    => !empty($arr['enable']) ? 1 : 0,
            );
        }
        return $state;
    }

    /**
     * 升级后回填快照：只改 installed/enable 两个键，其余字段保留包内新版内容。
     * fail-safe：插件已被本包移除（目录/conf.json 不存在）或包内 conf.json 非法 JSON ⇒ 跳过
     * （宁可停在包内出厂态，也不写坏文件）；回填失败留痕。
     *
     * @param array       $state snapshotPluginState() 的返回值
     * @param string|null $base  站点根（默认 APP_PATH）
     * @return int 实际改写条数
     */
    public static function restorePluginState(array $state, $base = NULL)
    {
        $done = 0;
        $base = (NULL === $base) ? APP_PATH : rtrim($base, '/\\') . '/';
        foreach ($state as $dir => $flags) {
            if (!is_string($dir) || '' === $dir || str_contains($dir, '/') || str_contains($dir, '\\')) continue;
            $conffile = $base . 'plugins/' . $dir . '/conf.json';
            if (!is_file($conffile)) continue;
            $arr = xn_json_decode(file_get_contents($conffile));
            if (!is_array($arr)) {
                self::upgradeLogError('update_down: plugin conf invalid, skip state restore: ' . $dir);
                continue;
            }
            $want = array(
                'installed' => !empty($flags['installed']) ? 1 : 0,
                'enable'    => !empty($flags['enable']) ? 1 : 0,
            );
            $cur = array(
                'installed' => !empty($arr['installed']) ? 1 : 0,
                'enable'    => !empty($arr['enable']) ? 1 : 0,
            );
            if ($cur === $want) continue;
            // file_replace_var 对 json 走 xn_json_decode→array_merge→xn_json_encode，解码失败返回 FALSE 且不落盘
            $r = file_replace_var($conffile, $want, TRUE);
            if (FALSE === $r) {
                self::upgradeLogError('update_down: plugin state restore failed: ' . $dir);
                continue;
            }
            $done++;
        }
        return $done;
    }

    /**
     * 读取升级包**自陈版本**（包内 `core/config/config.default.php` 的 `version`）。
     *
     * 这是升级链上唯一的权威版本源：构建脚本（build-release.sh）把命令行版本同时写进
     * 包内 config.default.php 与商店 `cloud_version`，两者同源同值；而调用方 `updateDown()`
     * 收到的版本号来自后台引导期的 `code` 缓存（TTL 600s，且后台路径无人刷新；缓存冷时
     * admin.func.php 会回落到本站当前版本）⇒ 用它写回 config.php 会让**版本号永不前进**、
     * 后台永远提示可升级（真机实测：商店 1.0.3、升级成功、站点仍记 1.0.2）。
     *
     * @param string $zipPath 已下载到本地的升级包路径
     * @return string 合法版本号（形如 1.0.3）；无该条目/格式不合法一律返回 ''（调用方回落传入值）
     */
    public static function packageVersionFromZip($zipPath)
    {
        if (!is_string($zipPath) || '' === $zipPath || !is_file($zipPath)) return '';
        if (!class_exists('ZipArchive')) return '';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== TRUE) return '';
        $def = $zip->getFromName('core/config/config.default.php');
        $zip->close();
        if (!is_string($def) || '' === $def) return '';
        return preg_match("#^[ \t]*'version'[ \t]*=>[ \t]*'([0-9]+(?:\.[0-9]+){1,3})'#m", $def, $m) ? $m[1] : '';
    }

    /**
     * 升级后同步后台入口文件（发布打包轮）。
     *
     * 事实：出厂包**只带 `admin.php`**（config.default.php 的 admin_entry 出厂值），
     * 而安装向导成功后会把根目录 admin.php 随机改名（如 `admin.php`）并回写 config.admin_entry
     * ⇒ 对这类站点，`updateDown()` 的整包覆盖既 ①升级不到真正的入口文件（随机名文件不在包里，
     * 永远停在旧版本），又 ②把众所周知的 `admin.php` 重新写回根目录（改名站点因此多出一个可被扫描到的第二入口）。
     *
     * 处理：线上入口名合法且该文件确实存在时，用**包内新版入口内容**覆盖它（保留文件名与权限位），
     * 成功后删除包内带回的 `admin.php`。任一条不满足即维持原状：
     *   - 入口名非法（非 `^[A-Za-z0-9_-]{1,64}\.php$` / 含路径分隔 / realpath 逃出站点根）⇒ 不写，
     *     且**保留** admin.php（防配置被改成任意路径写；admin_entry 已在 KV 剥除名单内，属纵深防御）
     *   - 线上入口文件不存在或是软链 ⇒ 不写且**保留** admin.php（否则会把后台彻底锁死，包内 admin.php 至少还能作为线索）
     *   - 包内无 admin.php（非本出厂形态）⇒ 不动
     *   - 内容已一致 ⇒ 只删 admin.php
     *
     * @param string|null $base 站点根（默认 APP_PATH；仅供离线/沙箱场景注入）
     * @return string noop|removed|synced|skipped
     */
    public static function syncAdminEntry($base = NULL)
    {        $base = (NULL === $base) ? APP_PATH : rtrim($base, '/\\') . '/';
        $packed = $base . 'admin.php';
        if (!is_file($packed) || is_link($packed)) return 'noop';
        $conf = function_exists('_SERVER') ? _SERVER('conf') : NULL;
        $entry = (is_array($conf) && isset($conf['admin_entry'])) ? trim((string)$conf['admin_entry']) : '';
        if ('' === $entry || 'admin.php' === $entry) return 'noop'; // 未改名：包内 admin.php 就是本机入口
        // 入口名白名单（与安装向导同口径 + 末尾必须是 .php）
        if (!preg_match('#^[A-Za-z0-9_\-]{1,64}\.php$#', $entry) || basename($entry) !== $entry) {
            self::upgradeLogError('update_down: admin_entry invalid, keep packed admin.php: ' . $entry);
            return 'skipped';
        }
        $live = $base . $entry;
        if (is_link($live)) {
            self::upgradeLogError('update_down: admin entry is a symlink, keep packed admin.php: ' . $entry);
            return 'skipped';
        }
        if (!is_file($live)) {
            self::upgradeLogError('update_down: admin entry file missing, keep packed admin.php: ' . $entry);
            return 'skipped';
        }
        // 纵深：目标必须真实落在站点根内（防软链目录/相对路径逃逸）
        $realLive = realpath($live);
        $realBase = realpath($base);
        if (FALSE === $realLive || FALSE === $realBase || strpos($realLive, rtrim($realBase, '/\\') . DIRECTORY_SEPARATOR) !== 0) {
            self::upgradeLogError('update_down: admin entry out of site root, keep packed admin.php: ' . $entry);
            return 'skipped';
        }
        $new = file_get_contents($packed);
        if (!is_string($new) || '' === $new) {
            self::upgradeLogError('update_down: packed admin entry unreadable, keep as is');
            return 'skipped';
        }
        $old = file_get_contents($live);
        if ($old === $new) {
            // 已是同一份代码：入口无需改写，仅清掉包内带回的默认名文件
            @unlink($packed);
            return 'removed';
        }
        $mode = fileperms($live);
        $r = file_put_contents_try($live, $new);
        if (FALSE === $r || $r !== strlen($new) || file_get_contents($live) !== $new) {
            self::upgradeLogError('update_down: admin entry sync failed: ' . $entry);
            return 'skipped'; // 保留 admin.php，线上入口维持旧版（可用但未更新）
        }
        FALSE !== $mode and @chmod($live, $mode & 0777);
        @unlink($packed);
        return 'synced';
    }

    /**
     * 站群批量添加：解析多行「域名|站点名|模板目录|伪静态档位」文本，逐行归一后写回 core/config/domain.php 的 $site 数组（file_replace_var 物理改写）。
     * 行处理：get_domain() 归一为可注册根域（非法即跳行）；不足 4 段跳行；已存在的域名跳行（不覆盖）；
     * path 含 `..` 或以 `/` 开头归空、不存在则随机取可用模板目录（无可用时 default）；
     * site_rewrite 空或 >=4 时 rand(0,3) 兜底。
     * @param string $input_text 上游调用方传入的多行原始文本（转义形态取决于取参路径，本方法不再转义）
     * @return string 固定汇总文案「批量添加完成，共添加 N 条记录」（写盘结果不回读校验）
     */
    public static function siteBatchAdd($input_text)
    {
        global $domain;
        $site = isset($domain['site']) && is_array($domain['site']) ? $domain['site'] : array();
        $lines = explode("\n", trim($input_text));
        $add_count = 0;
        $available_dirs = \App\Service\System\TemplateService::availableDirs();
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line == '') continue;
            $parts = explode('|', $line);
            if (count($parts) < 4) continue;
            list($site_domain, $site_name, $path, $site_rewrite) = $parts;
            $site_domain  = get_domain(trim($site_domain));
            $site_name    = trim($site_name);
            $path         = trim($path);
            if (str_contains($path, '..') || str_starts_with($path, '/')) {
                $path = '';
            }
            $site_rewrite = intval(trim($site_rewrite));
            if ($site_domain == '') continue;
            if (isset($site[$site_domain])) continue;
            if (empty($site_rewrite) || $site_rewrite >= 4) {
                $site_rewrite = rand(0, 3);
            }
            $template_dir = \App\Service\System\TemplateService::templateRoot();
            if ($path == '' || !is_dir($template_dir . $path)) {
                if (!empty($available_dirs)) {
                    $path = $available_dirs[array_rand($available_dirs)];
                } else {
                    $path = 'default';
                }
            }
            $site[$site_domain] = array(
                'site_name' => $site_name,
                'site_domain' => $site_domain,
                'path' => $path,
                'site_rewrite' => $site_rewrite,
            );
            $add_count++;
        }
        file_replace_var(APP_PATH . 'core/config/domain.php', array('site' => $site));
        return "批量添加完成，共添加 {$add_count} 条记录";
    }
}
