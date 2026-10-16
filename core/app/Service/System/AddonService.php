<?php
namespace App\Service\System;

/**
 * 插件域服务（plugins/，W0 由 addons/ 改名，存量自动迁移）：编译期钩子注入 / overwrite 白名单替换 / conf.json 读写 / 生命周期 / 启用前校验 / 运行期引导
 *
 * 插件系统改造（R0-R3；插件契约与开发手册见 .skills/插件开发.md）：
 *  - R0-1 listing() 惰性接线 init()（原根因：init 全库 0 调用 → 后台插件列表恒空）
 *  - R0-2 键归一：真实数据键 enable/installed（AddonManageService 负责 enable→enabled 展示映射）
 *  - R0-3 setting_url 指向真实路由 addon-setting（原 addons-setting 路由不存在=死链）
 *  - R1-2 verifyAddonCode()：启用/安装前校验（hook `<?php exit;` 头强约定 + token_get_all TOKEN_PARSE 语法闸；
 *    初版用 php_strip_whitespace——只做词法 strip，parse error 不报错，实测无效已弃）
 *  - R1-3 hook 签名失效：hook 文件变化自动触发编译产物重建（sidecar `.hooksig`），不再只靠后台启停清缓存
 *  - R1-4 install/uninstall 生命周期脚本（install.php/uninstall.php，经 _include 编译管线）+ dependencies + software_version 校验，脚本异常回滚标志
 *  - R2-1 拆除官方商店 legacy（officialStore/officialList/officialTotal/officialRead/siteid + addons_OFFICIAL_URL：
 *         旧占位域名 example.com + siteid=md5(auth_key+IP) 明文 HTTP 外发踩 auth_key 红线 + kv life=0 恒久缓存。
 *         占位域名已并入本站域名（配置项 `cloud_host`））
 *  - R3-1 boot()：运行期引导——enabled 插件 bootstrap.php 自动装载 + addon.boot 事件（index.php 接线，SKIP_ROUTE 之前=后台同样生效）
 *  - R3-2 删除主题注册表（$themes/$themelist——主题域唯一权威 = TemplateService::loadState/templateList()）
 *  - R3-3 overwrite 白名单（允许 core/model、core/admin/html、core/admin/route、route、template、core/lang、core/admin/lang；其余路径一律不替换）
 *  - R3-4 clearTmpDir 精确清编译产物（保护 .breaker_state.php/.sess_breaker_state.php/.sess_col_limits.php/lock_*.lock）
 *
 * 信任边界（与 AdminMenuService 监听器约定一致）：插件代码 = 受信代码。启用/安装前置校验只挡
 * "机械性损坏"（语法错、缺 hook 头、缺依赖、版本不符），不做语义沙箱。
 */
class AddonService
{
    /** 插件静态缓存（每 FPM 请求重建；测试可用 resetRuntimeCache() 清空） */
    private static $addons_paths = array();
    private static $addonss = array();
    private static $g_include_slot_kv = array();
    private static $initialized = false;
    /** enabledPaths() / hooksSignature() 的请求级缓存（原函数级 static，改为属性以便测试复位） */
    private static $enabledPathsCache = NULL;
    private static $hooksSigCache = NULL;
    /** hook 文件名→路径列表缓存（compileSourceFileCallback 原函数级 static；提为属性以便 resetRuntimeCache 复位——新增 hook 文件在同进程内立即可见） */
    private static $hooksListCache = NULL;
    /** hook 签名聚合表缓存（单一 .addon_hooksigs.json 取代每产物一个空 .hooksig sidecar） */
    private static $hooksSigMap = NULL;
    /**
     * 最近一次商店插件清单拉取是否失败（FALSE=成功/未拉取）。
     * 供 AddonManageService/插件列表区分「商店未收录」与「商店暂不可达」——旧实现两者不可分辨，
     * 一次网络抖动会连"商店标识"一起静默消失，详见 storePlugins() docblock。
     */
    private static $storeFetchFailed = FALSE;
    /** 最近一次 enable/install 校验失败原因（''=无；AddonManageService::toggle 透传给 message/audit） */
    public static $lastError = '';

    /**
     * overwrite 白名单（R3-3）：允许被插件 overwrite/ 目录替换的源文件前缀。
     * 显式排除面：core/config（配置）、core/func、core/db、入口文件（index.php/admin entry）、
     * core/lib、core/app（PSR-4 直载根本不经编译管线，替换无效果）。新目录白名单需评审后追加。
     */
    const OVERWRITE_ALLOWED_ROOTS = array(
        'core/model/',
        'core/admin/html/',
        'core/admin/route/',
        'route/',
        'template/',
        'core/lang/',
        'core/admin/lang/',
    );

    /**
     * 编译管线主入口（CLAUDE.md「当前架构·编译管线」）：按「源 mtime + overwrite mtime + hook 签名」判定失效，
     * 失效则 编译(hook 内联/overwrite 替换) → 模板 include 展开 → 压缩 → 产物语法闸 后写缓存；
     * 产物坏时沿用旧产物，无旧产物降级为合法空产物（不推进签名、每请求重试）。
     * @param string $srcfile 源文件绝对路径
     * @return string 编译产物绝对路径（调用方 include 该文件）
     */
    public static function includeFile($srcfile)
    {
        $conf = _SERVER('conf');
        $tmpfile = $conf['cache_path'] . substr(str_replace('/', '_', $srcfile), strlen(\APP_PATH));
        $srcMtime = 0;
        if (is_file($srcfile)) $srcMtime = filemtime($srcfile);
        $overwriteFile = self::findOverwrite($srcfile);
        // disabled_addons 下 compileSourceFile 直读源文件（不走 overwrite），mtime 也不应计入，避免空重建
        if ($overwriteFile !== $srcfile && is_file($overwriteFile) && empty($conf['disabled_addons'])) {
            $srcMtime = max($srcMtime, filemtime($overwriteFile));
        }
        $tmpMtime = is_file($tmpfile) ? filemtime($tmpfile) : 0;
        $tmpEmpty = is_file($tmpfile) && filesize($tmpfile) == 0;
        // R1-3 hook 签名失效：hook 文件 mtime/size 或启用集合变化 → 强制重建。
        // 原实现只跟踪源文件与 overwrite 文件的 mtime，改 hook 文件永远不生效（须后台启停清缓存）。
        $hookSig = self::hooksSignature();
        // hook 签名存取走聚合表（单一 .addon_hooksigs.json；旧方案每产物一个 .hooksig sidecar——
        // 无插件启用时全是 0 字节空文件，纯垃圾。本方案保留自动失效语义，只改存储形态为 sidecar JSON）
        $storedSig = self::hooksSigGet($tmpfile);
        $sigStale = ((NULL !== $storedSig) || ('' !== $hookSig)) && $storedSig !== $hookSig;
        if (!is_file($tmpfile) || $tmpEmpty || DEBUG > 1 || $sigStale || ($srcMtime && $tmpMtime && $srcMtime > $tmpMtime)) {
            $s = self::compileSourceFile($srcfile);
            $s = self::processTemplateIncludes($s);
            $s = self::compressOutput($s, $tmpfile);
            // ㉑：最终产物语法闸——解析失败拒写坏内容并响亮记日志；有旧产物沿用旧码；
            // 无旧产物降级为**合法空产物**（include=no-op，页面缺块但站活——写坏内容=include 即 parse error 全站白屏）。
            // 降级路径不推进签名 → 每请求重试直至源/hook 修复后自动恢复正常编译（推会使空产物固化）。
            $parseErr = self::artifactParseError($s);
            $fgRejected = (NULL !== $parseErr);
            if ($fgRejected) {
                xn_log('compile artifact rejected ' . $tmpfile . ' :: ' . $parseErr, 'addon_compile_error');
                if (!$tmpEmpty && is_file($tmpfile) && (int)filesize($tmpfile) > 0) {
                    return $tmpfile;
                }
                $s = "<?php "; // 合法空产物降级
                file_put_contents_try($tmpfile, $s);
                return $tmpfile; // 签名不推进（hooksSigSet 跳过）→ 每请求重试
            }
            file_put_contents_try($tmpfile, $s);
            self::hooksSigSet($tmpfile, $hookSig);
        } elseif ($srcMtime && $tmpMtime && $srcMtime < $tmpMtime) {
            // mtime 错乱防护：源时间戳早于缓存（文件复制/恢复导致时间戳倒退）时 mtime 判定失效，
            // 编译后与缓存内容比对，内容不同则重建，避免旧缓存永不过期（如模板常量改版）
            // 注意：$old 必须在压缩前读取（PHP 压缩档位会先写盘再 strip，压缩后读取会拿到中间态）
            $old = @file_get_contents($tmpfile);
            $s = self::compileSourceFile($srcfile);
            $s = self::processTemplateIncludes($s);
            $s = self::compressOutput($s, $tmpfile);
            if (md5($s) !== md5((string)$old)) {
                file_put_contents_try($tmpfile, $s);
            }
            self::hooksSigSet($tmpfile, $hookSig);
        }
        return $tmpfile;
    }

    /**
     * 当前启用插件全部 hook 文件的特征签名（path|mtime|size）。
     * '' = 无启用插件或无 hook 文件（含 disabled_addons=1）——此时不参与失效判定。
     * conf.json 的 enable 标志变化仍依赖后台 toggle 的 clearTmpDir（手改 conf.json 不清缓存=既有语义）。
     */
    public static function hooksSignature()
    {
        if (NULL !== self::$hooksSigCache) return self::$hooksSigCache;
        $conf = _SERVER('conf');
        if (!empty($conf['disabled_addons'])) {
            self::$hooksSigCache = '';
            return self::$hooksSigCache;
        }
        $raw = '';
        foreach (self::enabledPaths() as $path => $pconf) {
            $hookpaths = glob(\APP_PATH . 'plugins/' . file_name($path) . '/hook/*.*');
            if (!is_array($hookpaths)) continue;
            sort($hookpaths);
            foreach ($hookpaths as $hp) {
                $raw .= $hp . '|' . @filemtime($hp) . '|' . @filesize($hp) . "\n";
            }
        }
        self::$hooksSigCache = ('' === $raw) ? '' : md5($raw);
        return self::$hooksSigCache;
    }

    /** 聚合签名表文件（cache_path/.addon_hooksigs.json：{编译产物basename: hookSig}；json 后缀天然不被 clearTmpDir 扁平删除） */
    private static function hooksSigMapFile()
    {
        $conf = _SERVER('conf');
        return $conf['cache_path'] . '.addon_hooksigs.json';
    }

    /**
     * 从聚合签名表（cache_path 下 .addon_hooksigs.json，请求级 static 装载）读某产物的历史 hook 签名。
     * @param string $tmpfile 编译产物路径（按 basename 作键）
     * @return string|NULL 存储的签名；NULL=该产物无签名记录
     */
    private static function hooksSigGet($tmpfile)
    {
        if (NULL === self::$hooksSigMap) {
            $f = self::hooksSigMapFile();
            $decoded = is_file($f) ? json_decode((string)file_get_contents($f), TRUE) : array();
            self::$hooksSigMap = is_array($decoded) ? $decoded : array();
        }
        $k = basename($tmpfile);
        return array_key_exists($k, self::$hooksSigMap) ? (string)self::$hooksSigMap[$k] : NULL;
    }

    /**
     * 把产物当前 hook 签名写回聚合表；签名未变化时跳过落盘（免重复 IO）。
     * @param string $tmpfile 编译产物路径（按 basename 作键）
     * @param string $sig hooksSignature() 的 md5 签名（可为空串=无启用 hook）
     * @return void
     */
    private static function hooksSigSet($tmpfile, $sig)
    {
        self::hooksSigGet($tmpfile); // 确保表已装载
        $k = basename($tmpfile);
        if (array_key_exists($k, self::$hooksSigMap) && (string)self::$hooksSigMap[$k] === (string)$sig) return;
        self::$hooksSigMap[$k] = $sig;
        file_put_contents_try(self::hooksSigMapFile(), json_encode(self::$hooksSigMap));
    }

    /** 模板层（view::_file）签名失效判定：hook 文件集合/内容变化 → 模板产物需重建（此前模板 hook 改动不生效） */
    public static function hookSigStaleFor($tmpfile)
    {
        $sig = self::hooksSignature();
        $stored = self::hooksSigGet($tmpfile);
        return ((NULL !== $stored) || ('' !== $sig)) && $stored !== $sig;
    }

    /** 模板层产物重建后保存签名 */
    public static function hookSigSave($tmpfile)
    {
        self::hooksSigSet($tmpfile, self::hooksSignature());
    }

    /**
     * hook body 拼接的模式平衡：统计 body 内 php 开/闭标签字面出现次数，
     * 使拼接后结束态与拼接位既有模式一致——PHP 位（endInPhp=TRUE）结束须回到 PHP 态（缺开补开），
     * HTML 位结束须回到文本态（缺闭补闭）。标签序列出现在字符串字面量中时计数失衡属已知边界。
     */
    private static function balancePhpMode($t, $endInPhp)
    {
        $opens = 0;
        $closes = 0;
        if (preg_match_all('#<\?php\b#i', $t, $m)) $opens = count($m[0]);
        if (preg_match_all('#\?>#', $t, $m)) $closes = count($m[0]);
        $delta = $closes - $opens;
        if ($endInPhp && $delta > 0) $t .= str_repeat('<?php', $delta);
        if (!$endInPhp && $delta < 0) $t .= str_repeat('?>', -$delta);
        return $t;
    }

    /**
     * 模板 include 标签处理（<template include="...">...</template>）
     */
    private static function processTemplateIncludes($s)
    {
        for ($i = 0; $i < 10; ++$i) {
            $s = preg_replace_callback('#<template\sinclude="(.*?)">(.*?)</template>#is', '_include_callback_1', $s);
            if (!str_contains($s, '<template')) break;
        }
        return $s;
    }

    /**
     * 生产模式压缩（php_strip_whitespace / HTML 压缩），与既有口径一致
     */
    private static function compressOutput($s, $tmpfile)
    {
        $conf = _SERVER('conf');
        $ext = file_ext($tmpfile);
        $isHtml = in_array($ext, array('htm', 'html'));
        if ('php' == $ext && 0 == DEBUG && $conf['compress'] > 0) {
            // php_strip_whitespace 需要真实文件：写独立临时文件，避免污染正式缓存（防护分支 $old 比对依赖）
            $stripFile = $tmpfile . '.strip.tmp';
            file_put_contents_try($stripFile, $s);
            $s = trim(php_strip_whitespace($stripFile));
            @unlink($stripFile);
        } elseif ($isHtml && 0 == DEBUG && $conf['compress'] > 0) {
            // HTML 档位口径：先写盘再读回（hook 处理依赖文件），写独立临时文件避免污染正式缓存（防护分支 $old 比对依赖）
            $stripFile = $tmpfile . '.strip.tmp';
            file_put_contents_try($stripFile, $s);
            $s = self::compileSourceFile($stripFile);
            @unlink($stripFile);
            if (1 == $conf['compress']) {
                $s = str_replace(array("\t"), '', $s);
                $compressed = preg_replace(array("#> *([^ ]*) *<#", "#<!--[\\w\\W\r\\n]*?-->#", "# \"#", '#>\s+<#', "#/\*[^*]*\*/#", "//", '#/\*[\s\S]*?\*/#', "#>\s+\r\n#"), array(">\\1<", '', "\"", '><', '', '', '', '>'), $s);
                if ($compressed !== null) $s = $compressed;
            } elseif (2 == $conf['compress']) {
                $compressed = preg_replace(array("#> *([^ ]*) *<#", "#[\s]+#", "#<!--[\\w\\W\r\\n]*?-->#", "# \"#", "#/\*[^*]*\*/#", "//", '#/\*[\s\S]*?\*/#'), array(">\\1<", ' ', '', "\"", '', '', '><', ''), $s);
                if ($compressed !== null) $s = $compressed;
            }
        }
        return $s;
    }

    /**
     * <template include="..."></template> 的 preg_replace_callback 回调（模板编译期展开）。
     * 四重收口（S9/VULN-C02）：路径须解析进 template/、plugins/、core/admin/html/ 白名单前缀
     * （拒绝对路径/../、协议包装符）；读入内容重剥原生 php 段后过 TemplateService/LexicalSafety 内容闸，
     * 命中即整段丢弃（fail-closed，闸异常同样丢弃并记 addon_compile_error）；slot 标签用父层传入内容填充。
     * @param array $m 正则捕获组：$m[1]=include 路径、$m[2]=slot 原文
     * @return string 展开后的模板片段内容；任一安全闸触发返回空串（整标签被替换为空）
     */
    public static function includeTemplateCallback(array $m)
    {
        // 安全闸（S9）：此标签回调历史零校验，file_get_contents($m[1]) 可读任意路径——
        // 绝对路径/../穿越/phar:// 入口（全仓 14 处 unserialize allowed_classes=false 对 phar stub 触发无效）。
        // 红线不动编译规则（view.php 正则），仅收紧回调取文件语义：必须解析进 template/、plugins/、core/admin/html/ 前缀
        $path = (string) $m[1];
        if ($path === '' || str_contains($path, "\0") || preg_match('#://|\\\\#', $path) || str_contains($path, '..')) {
            return '';
        }
        $base = defined('APP_PATH') ? APP_PATH : './';
        $real = realpath($base . ltrim($path, '/'));
        if ($real === FALSE) {
            return '';
        }
        $allowedRoots = array(
            realpath($base . 'template'),
            realpath($base . 'plugins'),
            realpath($base . 'core/admin/html'),
        );
        $ok = FALSE;
        foreach ($allowedRoots as $root) {
            if ($root !== FALSE && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                $ok = TRUE;
                break;
            }
        }
        if (!$ok) {
            return '';
        }
        $r = @file_get_contents($real);
        if ($r === FALSE) {
            return '';
        }
        // 上线前审计修复（VULN-C02 第二向量）：本回调在 view.php 里位于
        // `preg_replace('#(?:\<\?.*?\?\>|\<\?.*)#s','',$s)` **之后**执行，此前 file_get_contents
        // 的结果原样返回 ⇒ 被 include 的文件里的原生 php 段直达编译产物并执行（与 {inc:} 同一
        // 根因）。此处做等价收口：重剥原生 PHP + 重跑内容闸，命中即整段丢弃；闸不可用 fail-closed。
        // 被包含内容只应是模板片段（HTML + 模板指令），不含原生 PHP。
        $r = preg_replace('#(?:\<\?.*?\?\>|\<\?.*)#s', '', $r);
        try {
            $incFindings = \App\Service\System\TemplateService::legacyRegexFindings($r);
            if (empty($incFindings)) {
                $incFindings = \App\Service\System\LexicalSafetyService::scanTemplate($r);
            }
            if (!empty($incFindings)) {
                if (function_exists('xn_log')) {
                    $first = $incFindings[0];
                    $reason = is_array($first) ? ((string)($first['kind'] ?? '') . ' ' . (string)($first['name'] ?? '')) : (string)$first;
                    xn_log('template <template include> content gate rejected: ' . $reason, 'addon_compile_error');
                }
                return '';
            }
        } catch (\Throwable $e) {
            if (function_exists('xn_log')) xn_log('template <template include> content gate error: ' . $e->getMessage(), 'addon_compile_error');
            return '';
        }
        preg_match_all('#<slot\sname="(.*?)">(.*?)</slot>#is', $m[2], $m2);
        if (!empty($m2[1])) {
            $kv = array_combine($m2[1], $m2[2]);
            self::$g_include_slot_kv += $kv;
            foreach (self::$g_include_slot_kv as $slot => $content) {
                $r = preg_replace('#<slot\sname="' . $slot . '"\s*/>#is', $content, $r);
            }
        }
        return $r;
    }

    /**
     * W0：存量目录一次性迁移 addons/ → plugins/。
     * 触发条件：plugins/ 不存在（或为空且无任何插件子目录）且 addons/ 存在 → 整目录 rename。
     * 失败（权限/占用）静默返回、下次请求重试；成功写 runtime/cache/.plugins_migrated 标记防重复扫描。
     * plugins/ 已有内容（新装/已迁移）一律不动。
     */
    private static function migrateAddonsDir()
    {
        $newDir = \APP_PATH . 'plugins';
        $oldDir = \APP_PATH . 'addons';
        $conf = _SERVER('conf');
        $marker = (isset($conf['cache_path']) ? $conf['cache_path'] : \APP_PATH . 'runtime/cache/') . '.plugins_migrated';
        if (is_file($marker)) return;
        if (is_dir($newDir)) {
            // 新目录已存在：检查是否为空（空=上次迁移半途/新装），非空视为已迁移
            $entries = glob($newDir . '/*', GLOB_ONLYDIR);
            if (is_array($entries) && count($entries) > 0) {
                @file_put_contents($marker, '1');
                return;
            }
        }
        if (!is_dir($oldDir)) {
            @file_put_contents($marker, '1');
            return;
        }
        $oldEntries = glob($oldDir . '/*');
        if (!is_array($oldEntries) || count($oldEntries) === 0) {
            @file_put_contents($marker, '1');
            return;
        }
        if (!is_dir($newDir)) @mkdir($newDir, 0755, TRUE);
        // 逐个插件目录迁移（目录级 rename，原子性最好）；失败保留原目录下次重试
        foreach ($oldEntries as $entry) {
            $name = file_name(rtrim($entry, '/'));
            if (!self::isValidDirName($name)) continue;
            if (is_dir($newDir . '/' . $name)) continue; // 目标已存在不覆盖
            @rename($entry, $newDir . '/' . $name);
        }
        // zip/散文件不迁移（历史遗留无消费方）；清编译缓存使旧产物失效
        self::clearTmpDir();
        @file_put_contents($marker, '1');
    }

    /**
     * R0-1 插件注册表装载：幂等（self::$initialized 闸，重复调用直接返回）。
     * 先做 addons/ → plugins/ 一次性迁移，再逐个扫描 plugins 子目录读取 conf.json，
     * 填充 self::$addons_paths / self::$addonss，并收集各插件 hook 目录的文件名清单。
     * @return void
     */
    public static function init()
    {
        // 幂等：每请求仅首次调用执行填充，重复调用直接返回（避免累积脏数据与重复 I/O）
        if (self::$initialized) return;
        self::$addons_paths = array();
        self::$addonss = array();
        self::migrateAddonsDir(); // W0：addons/ → plugins/ 一次性存量迁移（幂等，失败静默重试）
        self::$addons_paths = glob(\APP_PATH . 'plugins/*', GLOB_ONLYDIR);
        if (is_array(self::$addons_paths)) {
            foreach (self::$addons_paths as $path) {
                $dir = file_name($path);
                $conffile = $path . '/conf.json';
                if (!is_file($conffile)) continue;
                $arr = xn_json_decode(file_get_contents($conffile));
                if (empty($arr) || !is_array($arr)) continue;
                // R3-2：不再在此填充主题注册表（主题唯一权威 = TemplateService::loadState）。
                // 注意：此处不再调用 readByDir()（readByDir 自身会 init()，产生递归）——
                // 展示期默认值归一收敛到 readByDir()/AddonManageService::listPage()。
                $arr['hooks'] = array();
                $hookpaths = glob(\APP_PATH . "plugins/$dir/hook/*.*");
                if (is_array($hookpaths)) {
                    foreach ($hookpaths as $hookpath) {
                        $arr['hooks'][file_name($hookpath)] = $hookpath;
                    }
                }
                self::$addonss[$dir] = $arr;
            }
        }
        self::$initialized = true;
    }

    /**
     * 插件依赖缺失清单（$_dir => 需要的最低版本）；空数组 = 依赖满足。
     * type 参数保留签名兼容；'theme' 分支随 R3-2 主题注册表删除恒返回空数组（主题依赖在 TemplateService 域）。
     */
    public static function dependencies($dir, $type = 'addons')
    {
        self::init();
        $addons = isset(self::$addonss[$dir]) ? self::$addonss[$dir] : array();
        $arr = array();
        $dependencies = isset($addons['dependencies']) && is_array($addons['dependencies']) ? $addons['dependencies'] : array();
        foreach ($dependencies as $_dir => $version) {
            // 审查修复：任一侧缺 version 键时 version_compare(null) 在 PHP 8.1+ 触发 deprecation——统一 string 收口
            $depVer = isset(self::$addonss[$_dir]['version']) ? (string)self::$addonss[$_dir]['version'] : '';
            if (!isset(self::$addonss[$_dir]) || empty(self::$addonss[$_dir]['enable']) || -1 == version_compare($depVer, (string)$version)) {
                $arr[$_dir] = $version;
            }
        }
        return $arr;
    }

    /** 反向依赖：启用了依赖 $dir 的插件清单（$_dir => version）。'theme' 分支同 dependencies() 恒空。 */
    /**
     * 启用插件：目录名/conf.json 校验 → software_version 与 verifyAddonCode 前置闸 → 写 enable=1 →
     * overwrite 基线存量补录（已有基线绝不重算，防洗掉漂移证据）→ clearTmpDir 强制重编产物。
     * @param string $dir 插件目录名（仅 [A-Za-z0-9_-]）
     * @return bool TRUE=成功；FALSE=任一校验/写盘失败（原因见 self::$lastError）
     */
    public static function enable($dir)
    {
        self::$lastError = '';
        if (!self::isValidDirName($dir)) return self::fail('插件目录名非法');
        $conffile = \APP_PATH . "plugins/$dir/conf.json";
        if (!is_file($conffile)) return self::fail('conf.json 不存在');
        $pconf = xn_json_decode((string)file_get_contents($conffile));
        if (!self::checkSoftwareVersion(is_array($pconf) ? $pconf : array())) return FALSE;
        // R1-2：启用前置校验——语法坏插件此前=全站白屏（内联产物 parse error）或静默空转（空编译缓存），现在是明确报错
        if (TRUE !== self::verifyAddonCode($dir)) return FALSE;
        $r = file_replace_var($conffile, array('enable' => 1), TRUE);
        if (empty($r)) return self::fail('conf.json 写入失败');
        if (isset(self::$addonss[$dir])) self::$addonss[$dir]['enable'] = 1;
        // 存量补录：功能上线前安装的插件可能没有基线；已有基线绝不重算（否则重新启用会洗掉漂移证据）
        $pconfNow = xn_json_decode((string)file_get_contents($conffile));
        if (is_array($pconfNow) && is_dir(\APP_PATH . "plugins/$dir/overwrite") && !isset($pconfNow['overwrite_baseline'])) {
            self::overwriteBaselineWrite($dir);
        }
        self::clearTmpDir();
        return TRUE;
    }

    /**
     * R3-4 精确清缓存：只删编译产物（扁平 *.php/*.html/*.htm 与 *.hooksig sidecar）。
     * 原 rmdir_recusive(整个 cache_path) 会连坐删除：
     *   熔断状态（.breaker_state.php / .sess_breaker_state.php）、会话列宽缓存（.sess_col_limits.php）、
     *   互斥锁（lock_*.lock——删活动锁=破坏互斥）、下载/安装临时文件。
     * 编译产物形状 = 扁平文件名（路径分隔符已展平为 _）；html/htm 全删（模板编译产物仅落此目录）。
     *
     * ⚠ 维护入口（后台「设置→缓存」/ 系统升级 / 清空数据）必须走本方法，**不要**改回
     *   `rmdir_recusive($cache_path)`：那个函数会 glob 点文件，连坐删掉熔断状态与熔断期补偿删除队列
     *   （`.breaker_state.php`）、`.addon_hooksigs.json`、`.sess_col_limits.php` 与 `lock_*.lock`。
     * @param string|null $dir 待清理目录；NULL=取 conf['cache_path']（维护入口应显式传入路由层已解析的 $conf['cache_path']）
     */
    public static function clearTmpDir(?string $dir = NULL)
    {
        if (NULL === $dir) {
            $conf = _SERVER('conf');
            $dir = isset($conf['cache_path']) ? (string)$conf['cache_path'] : '';
        }
        $dir = (string)$dir;
        if ('' === $dir || !is_dir($dir)) return;
        '/' !== substr($dir, -1) and $dir .= '/';
        $files = glob($dir . '*');
        if (!is_array($files)) return;
        foreach ($files as $f) {
            if (!is_file($f)) continue;
            $name = file_name($f);
            if ('.' === $name[0]) continue;                        // 点文件：熔断/会话列宽等状态
            if (str_starts_with($name, 'lock_')) continue;            // 互斥锁
            $ext = strtolower(file_ext($name));
            $isSig = ('.hooksig' === substr($name, -8));
            if ($isSig) {
                xn_unlink($f);
                continue;
            }
            if ('php' !== $ext && 'html' !== $ext && 'htm' !== $ext) continue;   // zip/临时文件等不动
            if ('html' !== $ext && 'htm' !== $ext && !str_contains($name, '_') && 'model.min.php' !== $name) continue;
            xn_unlink($f);
        }
        xn_unlink($dir . 'model.min.php');
    }

    /**
     * 停用插件：写 conf.json enable=0 → 同步内存标志 → clearTmpDir 强制重编（摘除 hook 内联）。
     * @param string $dir 插件目录名
     * @return bool TRUE=成功；FALSE=目录名非法/conf.json 不存在或写失败（原因见 self::$lastError）
     */
    public static function disable($dir)
    {
        self::$lastError = '';
        if (!self::isValidDirName($dir)) return self::fail('插件目录名非法');
        $conffile = \APP_PATH . "plugins/$dir/conf.json";
        if (!is_file($conffile)) return self::fail('conf.json 不存在');
        $r = file_replace_var($conffile, array('enable' => 0), TRUE);
        if (empty($r)) return self::fail('conf.json 写入失败');
        if (isset(self::$addonss[$dir])) self::$addonss[$dir]['enable'] = 0;
        self::clearTmpDir();
        return TRUE;
    }

    /**
     * 安装插件：依赖完整性 → software_version → verifyAddonCode 三闸 → 写 installed=1/enable=1 →
     * overwrite 基线落库 → install.php 经 _include 编译管线执行；脚本抛 Throwable 则回滚安装标志
     * （事务语义，不留半安装态）并清缓存。
     * @param string $dir 插件目录名
     * @return bool TRUE=成功；FALSE=失败（原因见 self::$lastError）
     */
    public static function install($dir)
    {
        self::$lastError = '';
        if (!self::isValidDirName($dir)) return self::fail('插件目录名非法');
        $conffile = \APP_PATH . "plugins/$dir/conf.json";
        if (!is_file($conffile)) return self::fail('conf.json 不存在');
        // R1-4：依赖完整性前置（dependencies() 自带 init 兜底）
        $missing = self::dependencies($dir);
        if (!empty($missing)) {
            return self::fail('缺少依赖插件: ' . implode(', ', array_keys($missing)));
        }
        $pconf = xn_json_decode((string)file_get_contents($conffile));
        if (!self::checkSoftwareVersion(is_array($pconf) ? $pconf : array())) return FALSE;
        if (TRUE !== self::verifyAddonCode($dir)) return FALSE;
        $r = file_replace_var($conffile, array('installed' => 1, 'enable' => 1), TRUE);
        if (empty($r)) return self::fail('conf.json 写入失败');
        if (isset(self::$addonss[$dir])) {
            self::$addonss[$dir]['installed'] = 1;
            self::$addonss[$dir]['enable'] = 1;
        }
        // overwrite 基线：记录安装时刻被覆盖原文件的 md5（漂移检测锚点）
        self::overwriteBaselineWrite($dir);
        // R1-4：生命周期脚本（对齐主题 TemplateService::install：标志先行 → 脚本后执行 → 清缓存）。
        // 经 _include 编译管线加载（与 overwrite/hook 同一信任边界与安全口径）。
        $installfile = \APP_PATH . "plugins/$dir/install.php";
        if (is_file($installfile)) {
            try {
                include _include($installfile);
            } catch (\Throwable $e) {
                // 事务语义：脚本失败回滚安装标志，避免"半安装"状态
                file_replace_var($conffile, array('installed' => 0, 'enable' => 0), TRUE);
                if (isset(self::$addonss[$dir])) {
                    self::$addonss[$dir]['installed'] = 0;
                    self::$addonss[$dir]['enable'] = 0;
                }
                self::clearTmpDir();
                return self::fail('install.php 执行异常: ' . $e->getMessage());
            }
        }
        self::clearTmpDir();
        return TRUE;
    }

    /**
     * 卸载插件：写 installed=0/enable=0 → 清漂移缓存 → uninstall.php 经 _include 执行；
     * 脚本抛异常回滚为「已安装但停用」。conf.json 不存在视为已卸载（幂等返回 TRUE）。
     * @param string $dir 插件目录名
     * @return bool TRUE=已卸载；FALSE=写盘或卸载脚本失败（原因见 self::$lastError）
     */
    public static function uninstall($dir)
    {
        self::$lastError = '';
        if (!self::isValidDirName($dir)) return self::fail('插件目录名非法');
        $conffile = \APP_PATH . "plugins/$dir/conf.json";
        if (!is_file($conffile)) return TRUE; // 幂等：不存在视为已卸载
        $r = file_replace_var($conffile, array('installed' => 0, 'enable' => 0), TRUE);
        if (empty($r)) return self::fail('conf.json 写入失败');
        // 卸载后不再需要漂移结论，顺手清掉缓存条目（避免 runtime 缓存里留垃圾）
        self::overwriteDriftCacheClear($dir);
        if (isset(self::$addonss[$dir])) {
            self::$addonss[$dir]['installed'] = 0;
            self::$addonss[$dir]['enable'] = 0;
        }
        // R1-4：卸载脚本（标志先行，与 TemplateService::uninstall 同序）；异常回滚为"已安装但停用"
        $uninstallfile = \APP_PATH . "plugins/$dir/uninstall.php";
        if (is_file($uninstallfile)) {
            try {
                include _include($uninstallfile);
            } catch (\Throwable $e) {
                file_replace_var($conffile, array('installed' => 1, 'enable' => 0), TRUE);
                if (isset(self::$addonss[$dir])) {
                    self::$addonss[$dir]['installed'] = 1;
                    self::$addonss[$dir]['enable'] = 0;
                }
                self::clearTmpDir();
                return self::fail('uninstall.php 执行异常: ' . $e->getMessage());
            }
        }
        self::clearTmpDir();
        return TRUE;
    }

    /**
     * 插件目录名校验：仅允许 [A-Za-z0-9_-]，防路径穿越逃逸 plugins/ 目录
     */
    private static function isValidDirName($dir)
    {
        return is_string($dir) && 1 === preg_match('#^[A-Za-z0-9_-]+$#', $dir);
    }

    /** zip 包硬上限（压缩包 50MB / 解压总量 200MB / 条目 5000）——zip 炸弹粗闸 */
    const ZIP_MAX_FILESIZE = 52428800;
    const ZIP_MAX_UNCOMPRESSED = 209715200;
    const ZIP_MAX_ENTRIES = 5000;

    /**
     * 插件包 zip 条目放行裁定（插件目标）。
     * ⚠ 同口径独立实现：`.domian/mall_guard.php::mall_entry_blocked($entry, false)` 属另一站点
     * （商店服务端）部署件，**禁止跨站 include 共享代码**——只能对齐语义、各自维护、改动需两侧同步。
     * 口径：dotfile（.htaccess/.user.ini 等点开头 basename）一律拒；插件包放行 .php（插件即代码产品，
     * 语法闸在 verifyAddonCode）；其余脚本/可执行扩展硬拒。
     * @return bool true=拒绝该条目
     */
    public static function entryBlocked($entry)
    {
        $norm = strtolower(str_replace('\\', '/', (string)$entry));
        if ('' === $norm) return TRUE;
        $base = basename($norm);
        if ('' === $base || '.' === $base[0]) return TRUE;
        $ext = pathinfo($norm, PATHINFO_EXTENSION);
        if ('php' === $ext) return FALSE;
        $dangerExt = array('phar', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'pht', 'pl', 'py', 'cgi', 'sh', 'exe', 'jsp', 'asp', 'aspx');
        return in_array($ext, $dangerExt, TRUE);
    }

    /**
     * R2-3 客户端插件安装链：后台上传 zip → 条目闸 → 解压 → 落 plugins/<dir>/ → verifyAddonCode → 归一标志。
     * 成功后插件为"已落盘未启用"（installed=0/enable=0，包内自带标志一律归零）——启用必须经后台显式 install/enable 走完整闸。
     * $forceDir：手工指定插件目录名（留空=取包内唯一根子目录名；包根直接是 conf.json 时必须提供）。
     * $replace=TRUE 且目标目录已存在：旧目录整体挪入 runtime/backup/addon_<dir>_<ts>/（**保留不删**，可手工还原）。
     * 失败返回 FALSE + self::$lastError，且已发生的现场移动全部回滚。
     * @return FALSE|string 成功返回插件目录名
     */
    public static function installFromZip($zipFile, $replace = FALSE, $forceDir = '', $keepFlags = FALSE)
    {
        self::$lastError = '';
        $conf = _SERVER('conf');
        if (!is_file($zipFile)) return self::fail('上传包不存在');
        $size = (int)@filesize($zipFile);
        if ($size <= 0 || $size > self::ZIP_MAX_FILESIZE) return self::fail('包体积超限（上限 50MB）');
        if (!class_exists('ZipArchive')) return self::fail(lang('zip_ext_missing'));
        $zip = new \ZipArchive();
        if (TRUE !== $zip->open($zipFile)) return self::fail('ZIP 文件无法打开');
        // 1) 条目闸：路径穿越（绝对路径/..段/盘符，对齐 CloudOpsService::validateUpgradeZip）
        //    + 软链条目 + dotfile/危险扩展（对齐 mall_entry_blocked plugin 侧）+ zip 炸弹粗闸
        $uncompressed = 0;
        if ($zip->numFiles > self::ZIP_MAX_ENTRIES) {
            $zip->close();
            return self::fail('包条目数超限（上限 5000）');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string)$zip->getNameIndex($i);
            $norm = str_replace('\\', '/', $entry);
            if ('' === $norm || '/' === $norm[0] || str_contains($norm, ':') || preg_match('#(^|/)\.\.(/|$)#', $norm)) {
                $zip->close();
                return self::fail('非法包路径: ' . $entry);
            }
            $st = $zip->statIndex($i);
            if (is_array($st) && isset($st['external']) && (((intval($st['external']) >> 16) & 0120000) === 0120000)) {
                $zip->close();
                return self::fail('软链条目被拒绝: ' . $entry);
            }
            if (self::entryBlocked($norm)) {
                $zip->close();
                return self::fail('条目被拒绝: ' . $entry);
            }
            $uncompressed += (is_array($st) && isset($st['size'])) ? (int)$st['size'] : 0;
            if ($uncompressed > self::ZIP_MAX_UNCOMPRESSED) {
                $zip->close();
                return self::fail('解压总量超限（上限 200MB）');
            }
        }
        // 2) 解压到 cache_path 临时目录
        $tmpDir = (isset($conf['cache_path']) ? $conf['cache_path'] : \APP_PATH . 'runtime/cache/') . 'addon_upload_' . date('YmdHis') . '_' . mt_rand(100000, 999999) . '/';
        if (!@mkdir($tmpDir, 0755, TRUE)) {
            $zip->close();
            return self::fail('临时目录创建失败');
        }
        if (!$zip->extractTo($tmpDir)) {
            $zip->close();
            rmdir_recusive($tmpDir);
            return self::fail('解压失败');
        }
        $zip->close();
        // 3) 定位插件根目录与目录名：优先 $forceDir > 包内唯一根子目录名；包根直接 conf.json 且未指定名 → 拒绝
        $sourceDir = '';
        $dir = '';
        if (is_file($tmpDir . 'conf.json')) {
            $sourceDir = $tmpDir; // 包根即插件根（conf.json 在 zip 根层）
            if ('' !== (string)$forceDir) $dir = (string)$forceDir;
        } else {
            $subdirs = glob($tmpDir . '*', GLOB_ONLYDIR);
            $rootEntries = glob($tmpDir . '*');
            if (is_array($subdirs) && 1 === count($subdirs) && count($rootEntries) === 1) {
                $sourceDir = rtrim($subdirs[0], '/') . '/';
                $dir = ('' !== (string)$forceDir) ? (string)$forceDir : file_name(rtrim($subdirs[0], '/'));
            }
        }
        if ('' === $dir || '' === $sourceDir) {
            rmdir_recusive($tmpDir);
            return self::fail('无效插件包：需单一插件根目录（含 conf.json），或在表单填写目录名');
        }
        if (!self::isValidDirName($dir) || strlen($dir) > 64) {
            rmdir_recusive($tmpDir);
            return self::fail('目录名非法（仅允许 A-Za-z0-9_-，≤64 字符）: ' . $dir);
        }
        if (!is_file($sourceDir . 'conf.json')) {
            rmdir_recusive($tmpDir);
            return self::fail('无效插件包: conf.json 不存在');
        }
        // 4) 目标冲突：已存在 → 默认拒绝；replace=1 → 旧目录挪入 runtime/backup（保留可还原）
        $target = \APP_PATH . 'plugins/' . $dir;
        $backupPath = '';
        // 更新（$keepFlags）前先抓取旧标志。必须在挪走旧目录**之前**读：
        // 包内 conf.json 是发布时的状态（通常 installed/enable 全 0），
        // 只靠「不归零」是保不住标记的——见下方第 5 步的还原逻辑。
        $prevFlags = NULL;
        if ($keepFlags && is_file($target . '/conf.json')) {
            $pj = xn_json_decode((string)file_get_contents($target . '/conf.json'));
            if (is_array($pj)) {
                $prevFlags = array(
                    'installed' => isset($pj['installed']) ? (int)$pj['installed'] : 0,
                    'enable' => isset($pj['enable']) ? (int)$pj['enable'] : 0,
                );
            }
        }
        if (is_dir($target)) {
            if (!$replace) {
                rmdir_recusive($tmpDir);
                return self::fail('插件目录已存在: ' . $dir . '（覆盖请勾选强制替换）');
            }
            // 备份路径必须唯一：原实现只用 date('YmdHis')（秒级精度），
            // 同一秒内对同一插件做两次覆盖安装（批量/脚本化更新、重试）会算出同一路径，
            // 而 rename() 到**已存在的非空目录**会失败 ⇒ 第二次更新以「旧插件目录备份失败」
            // 中止（错误信息还指向错误的方向）。补随机后缀 + 存在性探测确保唯一。
            $stamp = date('YmdHis');
            $backupPath = \APP_PATH . 'runtime/backup/addon_' . $dir . '_' . $stamp;
            if (is_dir($backupPath)) {
                $backupPath .= '_' . bin2hex(random_bytes(4));
            }
            $n = 0;
            while (is_dir($backupPath) && $n++ < 10) {
                $backupPath = \APP_PATH . 'runtime/backup/addon_' . $dir . '_' . $stamp . '_' . bin2hex(random_bytes(4));
            }
            @mkdir(dirname($backupPath), 0755, TRUE);
            if (!@rename($target, $backupPath)) {
                rmdir_recusive($tmpDir);
                return self::fail('旧插件目录备份失败，已中止（未做任何改动）');
            }
        }
        // 5) 落位 → 归一标志 → R1-2 闸；任何一步失败全量回滚（新目录删、旧目录还原）
        if (!@rename(rtrim($sourceDir, '/'), $target)) {
            rmdir_recusive($tmpDir);
            if ('' !== $backupPath) @rename($backupPath, $target);
            return self::fail('插件目录落位失败');
        }
        rmdir_recusive($tmpDir);
        $conffile = $target . '/conf.json';
        $arr = xn_json_decode((string)file_get_contents($conffile));
        // 上传≠启用：包内自带 installed/enable 标志一律归零（商店更新走 $keepFlags=TRUE 保留现有启用状态）
        if (!$keepFlags && !empty($arr) && is_array($arr) && (!empty($arr['installed']) || !empty($arr['enable']))) {
            file_replace_var($conffile, array('installed' => 0, 'enable' => 0), TRUE);
        }
        // 保留标志：把旧状态**显式写回**。原先只做到「不主动归零」，而商店包内 conf.json
        // 是发布态（installed=0/enable=0）⇒ 更新一个「已安装但已停用」的插件会把它悄悄变成
        // 「未安装」，与方法注释承诺的语义相反（实测：更新前 installed=1/enable=0 → 更新后 0/0）。
        if ($keepFlags && is_array($prevFlags)) {
            file_replace_var($conffile, array(
                'installed' => (int)$prevFlags['installed'],
                'enable' => (int)$prevFlags['enable'],
            ), TRUE);
        }
        if (TRUE !== self::verifyAddonCode($dir)) {
            $err = self::$lastError;
            rmdir_recusive($target);
            if ('' !== $backupPath) @rename($backupPath, $target);
            return self::fail($err . '（已回滚，站点未改动）');
        }
        if ($keepFlags) self::clearTmpDir(); // 覆盖已启用插件的文件后，编译产物必须重建
        // overwrite 基线：升级重装按当前核心重算（原文件可能已随核心升级变化）
        self::overwriteBaselineWrite($dir);
        self::$lastError = '';
        return $dir;
    }

    /**
     * 统一失败出口：把原因写入 self::$lastError（供 AddonManageService 透传 message/audit）并恒返回 FALSE。
     * @param string $msg 失败原因
     * @return FALSE
     */
    private static function fail($msg)
    {
        self::$lastError = (string)$msg;
        return FALSE;
    }

    /**
     * R1-2 启用/安装前置校验。TRUE=通过；FALSE=不通过（原因在 self::$lastError）。
     * 1) conf.json 可解析；2) hook/*.php 必须有 "<?php exit;" 头（缺失=编译期把 <?php 字面内联进产物=全站 parse error）
     *    且剥头后载荷过语法闸；3) overwrite 递归目录内 .php、install.php/uninstall.php/setting.php/bootstrap.php 过语法闸。
     * 语法闸实现：token_get_all($code, TOKEN_PARSE)（解析器级，零外部依赖，PHP 8.x 语义一致；
     *    项目下限 8.0 见 core/install/index.php 的 NNCMS_PHP_MIN）。
     */
    public static function verifyAddonCode($dir)
    {
        if (!self::isValidDirName($dir)) return self::fail('插件目录名非法');
        $base = \APP_PATH . 'plugins/' . $dir . '/';
        if (!is_file($base . 'conf.json')) return self::fail('conf.json 不存在');
        $conf = xn_json_decode((string)file_get_contents($base . 'conf.json'));
        if (empty($conf) || !is_array($conf)) return self::fail('conf.json 解析失败');
        $hookpaths = glob($base . 'hook/*.php');
        if (is_array($hookpaths)) {
            foreach ($hookpaths as $hp) {
                $t = (string)file_get_contents($hp);
                // 发布前检查修复（E-2）：判头正则与编译期剥头统一为 \s+——原 \s* 放行的
                // 「<?phpexit;」（无空白）运行期剥头失配=安装成功但静默空转+每请求降级重试
                if (!preg_match('#^\s*<\?php\s+exit;#is', $t)) {
                    return self::fail('hook 文件缺少 "<?php exit;" 头: hook/' . file_name($hp));
                }
                $payload = preg_replace('#^\s*<\?php\s+exit;(.*?)(?:\?>)?\s*$#is', '\\1', $t);
                $err = self::phpParseError('<?php ' . $payload, 'hook/' . file_name($hp));
                if (NULL !== $err) return self::fail($err);
            }
        }
        $owDir = $base . 'overwrite';
        if (is_dir($owDir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($owDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile() || 'php' !== strtolower($f->getExtension())) continue;
                $rel = str_replace($base, '', $f->getPathname());
                $err = self::phpParseError((string)file_get_contents($f->getPathname()), $rel);
                if (NULL !== $err) return self::fail($err);
            }
        }
        foreach (array('install.php', 'uninstall.php', 'setting.php', 'bootstrap.php') as $script) {
            if (!is_file($base . $script)) continue;
            $err = self::phpParseError((string)file_get_contents($base . $script), $script);
            if (NULL !== $err) return self::fail($err);
        }
        self::$lastError = '';
        return TRUE;
    }

    /**
     * 语法闸：token_get_all($code, TOKEN_PARSE)——解析器级校验，语法错误抛 \ParseError（捕获后转拒绝原因）。
     * 零外部依赖（不依赖 php -l 可执行/php_strip_whitespace——后者只做词法 strip，parse error 不报错，实测无效）。
     * @return NULL 语法 OK；string 错误描述
     */
    /**
     * 最终编译产物语法闸（上线审核轮 ㉑）：对写盘前的拼接产物做 TOKEN_PARSE。
     * verifyAddonCode 闸的是剥头后 payload 单文件字符串，与拼接后的最终产物不是同一字符串——
     * balancePhpMode 计数失衡的静默方向（hook 含开标签字面量）产物 lint 全绿但会吞码/泄漏明文，
     * 只有最终产物级闸能拦。公开给 core/model/view.php 复用。
     */
    public static function artifactParseError($code)
    {
        try {
            token_get_all((string)$code, TOKEN_PARSE);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        return NULL;
    }

    /**
     * 单文件语法闸：token_get_all 带 TOKEN_PARSE 标志做解析器级校验（不依赖外部 php -l）。
     * @param string $code 待校验代码
     * @param string $label 错误描述前缀（文件相对名）
     * @return string|NULL NULL=语法 OK；字符串=「label PHP 语法解析失败: 原因」
     */
    private static function phpParseError($code, $label)
    {
        try {
            token_get_all($code, TOKEN_PARSE);
        } catch (\Throwable $e) {
            return $label . ' PHP 语法解析失败: ' . $e->getMessage();
        }
        return NULL;
    }

    /** R1-4：插件声明的目标系统版本（software_version）与当前 CMS 版本（conf['version']）兼容性 */
    private static function checkSoftwareVersion($pconf)
    {
        $need = isset($pconf['software_version']) ? trim((string)$pconf['software_version']) : '';
        if ('' === $need || '0' === $need) return TRUE;
        $conf = _SERVER('conf');
        $cms = isset($conf['version']) ? (string)$conf['version'] : '';
        if ('' === $cms) return TRUE;
        if (version_compare($cms, $need) < 0) {
            return self::fail('插件要求系统版本 ' . $need . '，当前 ' . $cms . '，禁止启用');
        }
        return TRUE;
    }

    /**
     * 已启用插件清单：扫描 plugins 各子目录的 conf.json，取 enable 且 installed 均真值者。
     * 请求级 static 缓存（self::$enabledPathsCache；resetRuntimeCache 可复位）。
     * @return array 键=插件目录绝对路径，值=conf.json 解码后的数组
     */
    public static function enabledPaths()
    {
        if (NULL !== self::$enabledPathsCache) return self::$enabledPathsCache;
        $returnPaths = array();
        $addonsPaths = glob(\APP_PATH . 'plugins/*', GLOB_ONLYDIR);
        foreach ($addonsPaths as $path) {
            $conffile = $path . '/conf.json';
            if (!is_file($conffile)) continue;
            $pconf = xn_json_decode(file_get_contents($conffile));
            if (empty($pconf) || !is_array($pconf)) continue;
            if (empty($pconf['enable']) || empty($pconf['installed'])) continue;
            $returnPaths[$path] = $pconf;
        }
        self::$enabledPathsCache = $returnPaths;
        return self::$enabledPathsCache;
    }

    /**
     * 读源并编译为内存字符串：disabled_addons 全局开关打开时直读原文件（不替换不内联）；
     * 否则先 findOverwrite 换源 → 剥 UTF-8 BOM → inlineHookMarkers 内联插件 hook 代码。
     * @param string $srcfile 源文件绝对路径
     * @return string|FALSE 编译后内容；file_get_contents 读失败时为 FALSE
     */
    public static function compileSourceFile($srcfile)
    {
        $conf = _SERVER('conf');
        if (!empty($conf['disabled_addons'])) {
            return file_get_contents($srcfile);
        }
        $srcfile = self::findOverwrite($srcfile);
        $s = file_get_contents($srcfile);
        if ($s !== '' && substr($s, 0, 3) === "\xEF\xBB\xBF") $s = substr($s, 3);
        return self::inlineHookMarkers($s);
    }

    /**
     * 对任意字符串执行 hook 标记内联：模板编译管线（view::_file）在「剥离模板原生 php」
     * 之后调用本方法内联 hook——插件受信代码的 php 段不参与模板剥离（此前顺序相反，wrapped 格式
     * hook 的逻辑整段被 view 剥离规则清空=开关/配置失效，HTML 壳无条件输出=假工作）。
     */
    public static function inlineHookMarkers($s)
    {
        $addonsPaths = self::enabledPaths();
        for ($i = 0; $i <= 10; ++$i) {
            if (str_contains($s, '<!--{hook') || str_contains($s, '// hook')) {
                if (empty($addonsPaths)) {
                    $s = preg_replace('#<!--{hook\s+(.*?)}-->#', '', $s);
                } else {
                    // 标记来源即拼接上下文：`<!--{hook}-->`（模板 HTML 位）→ @html@ 前缀内部标记；
                    // 源码原生 `// hook`（PHP 位）→ 普通标记。回调据此归一 hook body 格式。
                    $s = preg_replace('#<!--{hook\s+(.*?)}-->#', '// hook @html@\\1', $s);
                    $s = preg_replace_callback('#//\s*hook\s+(\S+)#is', 'addons_compile_srcfile_callback', $s);
                }
            } else {
                break;
            }
        }
        return $s;
    }

    /**
     * R3-3 overwrite 白名单：仅白名单前缀内的源文件允许被插件 overwrite/ 替换，
     * 其余（core/config、core/func、core/db、入口文件、core/app 等）一律忽略替换。
     * @return bool
     */
    public static function overwriteAllowed($srcfile)
    {
        if (!defined('APP_PATH')) return FALSE;
        $rel = ltrim(substr($srcfile, strlen(\APP_PATH)), '/');
        if ('' === $rel) return FALSE;
        foreach (self::OVERWRITE_ALLOWED_ROOTS as $root) {
            if (str_starts_with($rel, $root)) return TRUE;
        }
        return FALSE;
    }

    /**
     * overwrite 替换解析（R3-3 白名单内）：遍历启用插件 overwrite 目录下同相对路径的文件，
     * 命中则返回替换文件路径，多插件同文件时按 conf.json 的 overwrites_rank 取高者（同级后者胜）。
     * @param string $srcfile 源文件绝对路径
     * @return string 替换文件路径；无替换或不在白名单时原样返回 $srcfile
     */
    public static function findOverwrite($srcfile)
    {
        $addonsPaths = self::enabledPaths();
        if (empty($addonsPaths)) return $srcfile;
        if (!self::overwriteAllowed($srcfile)) return $srcfile;
        $len = strlen(\APP_PATH);
        $returnfile = $srcfile;
        $maxrank = 0;
        foreach ($addonsPaths as $path => $pconf) {
            $dir = file_name($path);
            $filepathHalf = substr($srcfile, $len);
            $overwriteName = file_name($srcfile);
            $overwriteFile = \APP_PATH . "plugins/$dir/overwrite/$filepathHalf";
            if (is_file($overwriteFile)) {
                $rank = isset($pconf['overwrites_rank'][$overwriteName]) ? $pconf['overwrites_rank'][$overwriteName] : 0;
                if ($rank >= $maxrank) {
                    $returnfile = $overwriteFile;
                    $maxrank = $rank;
                }
            }
        }
        return $returnfile;
    }

    /**
     * overwrite 基线扫描：扫描插件 overwrite/ 目录，
     * 返回 array(相对APP_PATH路径 => 安装时刻原文件md5)，原文件不存在记 '__ABSENT__'。
     * 白名单外的路径 findOverwrite 不生效，不纳入基线（避免恒定噪音）。
     */
    public static function overwriteBaselineScan($dir)
    {
        $map = array();
        $owRoot = \APP_PATH . "plugins/$dir/overwrite";
        if (!is_dir($owRoot)) return $map;
        $len = strlen($owRoot . '/');
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($owRoot, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $rel = substr(str_replace('\\', '/', (string)$f->getPathname()), $len);
            if (!self::overwriteAllowed(\APP_PATH . $rel)) continue;
            $orig = \APP_PATH . $rel;
            $map[$rel] = is_file($orig) ? (string)md5_file($orig) : '__ABSENT__';
        }
        ksort($map);
        return $map;
    }

    /**
     * overwrite 基线落库（写入 conf.json 的 overwrite_baseline）。
     * 调用时机：install（安装锚点）/ installFromZip（升级重装重算）/ enable（仅存量补录）。
     * 无 overwrite/ 目录时零写入直接成功。
     */
    public static function overwriteBaselineWrite($dir)
    {
        if (!self::isValidDirName($dir)) return FALSE;
        if (!is_dir(\APP_PATH . "plugins/$dir/overwrite")) return TRUE;
        $conffile = \APP_PATH . "plugins/$dir/conf.json";
        if (!is_file($conffile)) return FALSE;
        $r = file_replace_var($conffile, array('overwrite_baseline' => self::overwriteBaselineScan($dir)), TRUE);
        // 基线变了 ⇒ 漂移结论必然要重算（代码审查 #3 的缓存失效闸）
        self::overwriteDriftCacheClear($dir);
        return !empty($r);
    }

    /**
     * overwrite 漂移检测：当前原文件 md5 对比 conf.json 记录的安装基线。
     * 核心升级改动了被覆盖文件时，插件那份整文件替换会静默过时——
     * 本方法把它变成显式维护事件（后台插件列表告警徽章）。
     * 注意：enable() 只补录缺失基线、绝不重算已有基线（否则重新启用会洗掉漂移证据）。
     *
     * 代码审查 #3 修复——**结果缓存**：本方法原先每次调用都对 overwrite/ 全目录做
     * `RecursiveDirectoryIterator` 遍历 + `md5_file()`（当年在一台装有覆盖内容的站点上实测：
     * 402 个覆盖文件时目录遍历 0.31ms、md5 全部 **19.97ms**，其余是 stat 开销），而后台插件列表
     * 对**每个已装插件**都要调一次 ⇒ 页面成本随「插件数 × 覆盖文件数」线性放大。
     * ⚠ 该 402 是历史测量样本，不是仓库现状：出厂包内 5 个插件均不带 overwrite/ 内容
     *   （`find plugins -path '*overwrite*' -type f` 实测为 0），所以未装覆盖类插件的站点看不到这个成本，
     *   但缓存机制本身仍必要——插件一旦开始覆盖文件就会立刻命中。
     *
     * 缓存校验键**只取 conf.json 的 mtime**（0.0004ms，比目录遍历快约 771 倍）：
     * 任何基线写入（install / enable / 升级重装）都会改写 conf.json ⇒ mtime 变化 ⇒ 自动失效。
     * 刻意**不做**目录遍历来当有效性校验——实测遍历 0.31ms 与 19.97ms 的 md5 同量级，
     * 那样省不到关键部分（这正是第一版实现的问题：省了 md5，却仍付遍历成本）。
     *
     * 失效途径：① conf.json mtime 变化（基线写入的天然信号）
     * ② TTL 到期（兜住「核心文件被升级改动、但 conf.json 未变」的情形）
     * ③ overwriteBaselineWrite / 卸载时显式 cache_delete
     *
     * ⚠ 权衡（须知）：核心升级改动了被覆盖文件时，插件列表的漂移徽章最多滞后
     *   `$ttl` 秒才更新。该徽章是维护提示而非安全闸，滞后可接受；要立即刷新可在
     *   升级完成后调用 overwriteDriftCacheClear()。早前版本每次实时算，无滞后但每次付 20ms/插件。
     *
     * @return array{total:int,changed:array,missing:array}
     */
    public static function overwriteDrift($dir)
    {
        $res = array('total' => 0, 'changed' => array(), 'missing' => array());
        $conffile = \APP_PATH . "plugins/$dir/conf.json";
        $pconf = is_file($conffile) ? xn_json_decode((string)file_get_contents($conffile)) : array();
        $base = (is_array($pconf) && isset($pconf['overwrite_baseline']) && is_array($pconf['overwrite_baseline'])) ? $pconf['overwrite_baseline'] : array();
        if (empty($base)) return $res;
        $res['total'] = count($base);

        // 请求级记忆（同一请求内多次调用直接复用，零额外 IO）
        static $memo = array();
        $cacheKey = 'plugin_ow_drift_v1_' . md5((string)$dir);
        if (isset($memo[$cacheKey])) return $memo[$cacheKey];

        $ttl = 600;
        $mtime = is_file($conffile) ? (string)filemtime($conffile) : '0';
        $sig = $mtime . ':' . count($base);
        if (function_exists('cache_get')) {
            $hit = cache_get($cacheKey);
            if (is_array($hit) && isset($hit['sig'], $hit['res']) && (string)$hit['sig'] === $sig) {
                $memo[$cacheKey] = $hit['res'];
                return $hit['res'];
            }
        }

        $now = self::overwriteBaselineScan($dir);
        foreach ($base as $rel => $md5) {
            if (!isset($now[$rel])) { $res['missing'][] = $rel; continue; }
            if ((string)$now[$rel] !== (string)$md5) $res['changed'][] = $rel; // 含 __ABSENT__→核心新增同路径 的情况
        }
        sort($res['changed']);
        sort($res['missing']);

        if (function_exists('cache_set')) {
            cache_set($cacheKey, array('sig' => $sig, 'res' => $res), $ttl);
        }
        $memo[$cacheKey] = $res;
        return $res;
    }

    /**
     * 清除某插件的 overwrite 漂移缓存（基线变更 / 启停 / 卸载时调用）。
     */
    public static function overwriteDriftCacheClear($dir)
    {
        if (function_exists('cache_delete')) {
            cache_delete('plugin_ow_drift_v1_' . md5((string)$dir));
        }
    }

    /**
     * 编译期钩子标记（形如「斜杠空格 hook 空格 文件名.php」）的内联回调：
     * 钩子名 → 启用插件 hook 文件列表（self::$hooksListCache 请求级缓存，按 hooks_rank 倒序拼接）；
     * 文件名须匹配 [A-Za-z0-9_-]+ 加 .php/.htm/.html/.js 后缀，不匹配视为注释文本原样返回 $m[0]（防吞注释）；
     * php 钩子按拼接上下文（PHP 位/带 @html@ 前缀=HTML 位）剥 exit; 头并归一模式态；
     * 最后过 App::fire 的 addon.hook.<name> 事件桥（无监听器时零行为变化）。
     * @param array $m 正则捕获组：$m[0]=标记原文、$m[1]=钩子名
     * @return string 内联拼接出的钩子内容（可为空串=无匹配文件）；非法钩子名时回吐标记原文
     */
    public static function compileSourceFileCallback(array $m)
    {
        if (NULL === self::$hooksListCache) {
            $hooks = array();
            $addonsPaths = self::enabledPaths();
            if (empty($addonsPaths)) return '';
            foreach ($addonsPaths as $path => $pconf) {
                $dir = file_name($path);
                $hookpaths = glob(\APP_PATH . "plugins/$dir/hook/*.*");
                if (is_array($hookpaths)) {
                    foreach ($hookpaths as $hookpath) {
                        $hookname = file_name($hookpath);
                        $rank = isset($pconf['hooks_rank'][$hookname]) ? $pconf['hooks_rank'][$hookname] : 0;
                        $hooks[$hookname][] = array('hookpath' => $hookpath, 'rank' => $rank);
                    }
                }
            }
            foreach ($hooks as $hookname => $arrlist) {
                $arrlist = arrlist_multisort($arrlist, 'rank', FALSE);
                $hooks[$hookname] = arrlist_values($arrlist, 'hookpath');
            }
            self::$hooksListCache = $hooks;
        }
        $hooks = self::$hooksListCache;
        $s = '';
        $hookname = (string)$m[1];
        // 拼接上下文：标记来自模板 `<!--{hook}-->`（@html@ 前缀内部标记）= HTML 位；原生 `// hook` = PHP 位
        $isHtmlPoint = (str_starts_with($hookname, '@html@'));
        if ($isHtmlPoint) $hookname = substr($hookname, 6);
        // 钩子名合法性闸：注释文本里的「hook xxx」不是标记——原样保留原文。
        // 此前对无匹配 hook 的「标记」返回 ''，会把注释里的 // 连同文字吞掉并可能破坏语法
        //（实测：view.php 注释「// hook 的 kv 读取/...」被吞 // 后中文注释变裸代码=解析错误）。
        if (!preg_match('#^[A-Za-z0-9_-]+\.(php|htm|html|js)$#', $hookname)) return $m[0];
        if (!empty($hooks[$hookname])) {
            $fileext = file_ext($hookname);
            foreach ($hooks[$hookname] as $path) {
                $t = file_get_contents($path);
                if ('php' == $fileext && preg_match('#^\s*<\?php\s+exit;#is', $t)) {
                    // 剥头（修正）：exit; 后的可选闭合标签一并剥离——
                    // HTML 上下文 hook 约定头写「exit 分号加闭合标签」+ 逻辑用 php 标签包裹
                    // （裸语句在 HTML 拼接位=纯文本，旧实现会产出孤儿花括号 = 整页 parse error）；
                    // PHP 上下文裸语句格式向后完全兼容。
                    $t = preg_replace('#^\s*<\?php\s+exit;\s*(?:\?>)?(.*?)$#is', '\\1', $t);
                }
                if ('php' == $fileext) {
                    // 上下文归一：同一份 hook 可能被任一上下文的点引用，拼接后必须恢复拼接前的模式态——
                    // PHP 位：剥掉 wrapped 格式的首开标签（嵌套开标签=解析错误），并按开/闭计数补齐使 body 结束于 PHP 态
                    //   （否则 wrapped 格式的尾闭合标签会关闭 PHP 模式，引导体泄漏成文本直到源码里裸露的
                    //   php 开标签重开=解析错误）；HTML 位：body 必须以 php 开标签自含（裸语句格式包一层），
                    //   并保证结束于文本态（模板续 HTML）。
                    // 口径：计数按 php 开标签/闭标签字面出现次数——hook 体内字符串字面量包含这两个序列时会失衡，属已知边界。
                    if ($isHtmlPoint) {
                        if (!preg_match('#^\s*<\?php\b#', $t)) $t = "<?php\n" . $t . "\n?>";
                        $t = self::balancePhpMode($t, FALSE);
                    } else {
                        $t = preg_replace('#^(\s*)<\?php\b#', '\\1', $t, 1);
                        $t = self::balancePhpMode($t, TRUE);
                    }
                }
                $s .= $t;
            }
        }
        // 事件桥接（主线 3）：插件可 App::listen('addon.hook.<name>', ...) 注册编译期钩子；
        // 无监听器时 App::fire 原样返回 $s，零行为变化；监听器可返回字符串覆盖/追加钩子内容
        $s = \App::fire('addon.hook.' . $hookname, $s);
        return $s;
    }

    /**
     * R0-1 本地插件列表（惰性接线 init()——原实现依赖调用方先 init，实际 0 处满足 → 后台列表恒空）。
     * type=1 插件；type=2/3 主题（R3-2 起主题注册表收敛到 TemplateService::templateList()，此处恒返回空数组）。
     * 每条记录补 'dir' 键（conf.json 本身不含目录名，后台表格此前只能显示空）。
     */
    public static function listing($cond = array(), $orderby = array(), $page = 1, $pagesize = 20, $type = 1)
    {
        self::init();
        if (1 != $type) return array();
        $arr = self::$addonss;
        foreach ($arr as $_dir => $row) {
            $arr[$_dir]['dir'] = $_dir;
        }
        return arrlist_cond_orderby($arr, $cond, $orderby, $page, $pagesize);
    }

    /**
     * 单插件展示数据（默认值归一 + setting_url）。
     * R0-3：setting 路由名修正为 addon-setting（原 addons-setting 指向不存在的路由文件=死链）；
     * R2-1：官方商店合并逻辑整族拆除（official 恒空导致 have_upgrade/downloads 等字段失去数据源）。
     * $localFirst 参数保留签名兼容（本地数据是唯一数据源）。
     */
    /**
     * R3-1 运行期引导：装载 enabled 插件的 bootstrap.php（插件在此用 App::listen 注册事件监听），
     * 完成后触发 'addon.boot' 事件。bootstrap 直接 include（不经编译缓存：改动即时生效，
     * 语法已在 enable/install 时被 verifyAddonCode 闸住）；单插件引导异常只记日志不阻断整站。
     * 调用点：index.php（SKIP_ROUTE 判定之前 → 前台/后台/api 请求均可获得引导）。
     */
    public static function boot()
    {
        static $booted = FALSE;
        if ($booted) return;
        $booted = TRUE;
        $conf = _SERVER('conf');
        if (!empty($conf['disabled_addons'])) return;
        foreach (self::enabledPaths() as $path => $pconf) {
            $dir = file_name($path);
            $bootstrap = \APP_PATH . 'plugins/' . $dir . '/bootstrap.php';
            if (!is_file($bootstrap)) continue;
            try {
                include $bootstrap;
            } catch (\Throwable $e) {
                if (class_exists('\App\Service\System\LoggerService')) {
                    // 用 channel()（通道 + 级别 + 消息）；LoggerService::error() 现为 PSR-3 签名（首参为通道）
                    \App\Service\System\LoggerService::channel('addon_boot_error', 'error', 'bootstrap ' . $dir . ': ' . $e->getMessage());
                } else {
                    error_log('addon_boot_error: bootstrap ' . $dir . ': ' . $e->getMessage());
                }
            }
        }
        \App::fire('addon.boot', NULL);
    }

    /** 测试钩子：清空本进程静态缓存（生产禁调——静态缓存本就随 FPM 请求重建） */
    /* ---------------- B3：商店打通（检查更新 / 一键更新） ---------------- */

    /**
     * 最近一次商店清单拉取是否失败（页面用：失败时不能把"没查到"当成"商店未收录"）。
     * @return bool TRUE=拉取/解析失败（商店暂不可达）；FALSE=成功或尚未拉取
     */
    public static function storeFetchFailed(): bool
    {
        return self::$storeFetchFailed;
    }

    /** 商店服务端 base（conf['cloud_api']；空=未接商店，检查更新/更新功能关闭） */
    public static function storeBase()
    {
        $conf = _SERVER('conf');
        $u = trim(isset($conf['cloud_api']) ? (string)$conf['cloud_api'] : '');
        return '' === $u ? '' : rtrim($u, '/') . '/';
    }

    /**
     * 商店插件清单（600s 缓存；不可达返回空数组）。
     *
     * **数据源修正（重要）**：本方法原实现读 `{store}index.php?api=manifest`，
     * 但实测该端点**只返回模板**（49 条，`type` 全为 `2`），**插件条目恒为 0**
     * —— 于是「检查更新」永不命中、一键更新恒报「商店不存在该插件或已下架」。
     *
     * 正确的插件清单在 `{store}api.php` 的顶层 `plugins` 数组（实测 4 条），字段：
     *   dir / name / brief / author / version / software_version / icon / price
     *   / downloads / updated_at / download_url
     * 注意插件条目**没有 `type` 字段**（模板才有），故不能再按 `type===1` 过滤。
     *
     * 缓存只留所需子集（避免把整份 api.php 塞进缓存），600s 与其它商店端点同节奏。
     *
     * ⚠ **「拉取失败」与「商店确实没有插件」必须区分**（静默降级缺陷，分类导入修复轮同源）：
     *   旧实现无论成败都 `cache_set('store_plugins_v2', $out, 600)`，而失败时 `$out` 恰为空数组
     *   ⇒ 一次网络抖动就把**空表当"商店清单"缓存 10 分钟**：插件列表全部 `in_store=false`、
     *   商店标识（作者/下载量/更新于）整片消失、未安装插件还被误标「商店未收录」，且全程零日志。
     *   （实测复现：正常态命中 4/5 → 模拟一次失败后 0/5）
     *   现口径：只有**响应结构合法**（`plugins` 键存在且为数组；允许空数组——那是真的没插件）
     *   才写 600s 正缓存；拉取/解析失败**绝不写正缓存**，只写 30s 负缓存（防每请求都打商店），
     *   并写 `store_error` 通道（通道名含 error ⇒ DEBUG=0 也恒写）；页面用 storeFetchFailed()
     *   区分「商店未收录」与「商店暂不可达」。
     *
     * @return array<int,array> 已归一化的插件条目（download_url 已按商店主机改写；失败=空数组）
     */
    public static function storePlugins()
    {
        static $plugins = NULL;
        if (NULL !== $plugins) return $plugins;
        $base = self::storeBase();
        if ('' === $base) {
            $plugins = array();
            return $plugins;
        }
        $cached = cache_get('store_plugins_v2');
        if (is_array($cached)) {
            $plugins = $cached;
            return $plugins;
        }
        // 失败负缓存（30s）：上一次拉取刚失败过就别每请求再打商店；**不冒充"商店清单"**。
        if (NULL !== cache_get('store_plugins_fail')) {
            self::$storeFetchFailed = TRUE;
            $plugins = array();
            return $plugins;
        }
        $body = (string)https_request($base . 'api.php', '', '', 30, 0, 8 * 1048576);
        $arr = xn_json_decode($body);
        // 结构合法才认（`plugins` 键存在且为数组；空数组=商店确实没有插件，允许缓存）
        if (!is_array($arr) || !isset($arr['plugins']) || !is_array($arr['plugins'])) {
            self::$storeFetchFailed = TRUE;
            cache_set('store_plugins_fail', 1, 30);
            function_exists('xn_log') and xn_log(
                'storePlugins(): 商店插件清单拉取/解析失败（base=' . $base . '，响应 ' . strlen($body) . ' 字节）'
                . '——本次不写 600s 正缓存，商店标识暂不显示，30s 后自动重试',
                'store_error'
            );
            $plugins = array();
            return $plugins;
        }
        self::$storeFetchFailed = FALSE;
        cache_delete('store_plugins_fail');
        $raw = $arr['plugins'];
        $out = array();
        foreach ($raw as $e) {
            if (!is_array($e)) continue;
            $dir = isset($e['dir']) ? (string)$e['dir'] : '';
            if ('' === $dir || !self::isValidDirName($dir)) continue;
            $out[] = array(
                'dir' => $dir,
                'name' => isset($e['name']) ? (string)$e['name'] : '',
                'brief' => isset($e['brief']) ? (string)$e['brief'] : '',
                'author' => isset($e['author']) ? (string)$e['author'] : '',
                'version' => isset($e['version']) ? (string)$e['version'] : '',
                'software_version' => isset($e['software_version']) ? (string)$e['software_version'] : '',
                'icon' => isset($e['icon']) ? (string)$e['icon'] : '',
                'price' => isset($e['price']) ? (string)$e['price'] : '',
                'downloads' => isset($e['downloads']) ? (int)$e['downloads'] : 0,
                'updated_at' => isset($e['updated_at']) ? (int)$e['updated_at'] : 0,
                'download_url' => isset($e['download_url']) ? (string)$e['download_url'] : '',
                // 包体哈希：商店已补 sha256（2026-10-08 实测 4/4 与实际下载内容一致）。
                // 缺失或非 64 位 hex 一律归一为 '' ⇒ 调用方跳过校验而非误判失败。
                'sha256' => (isset($e['sha256']) && is_string($e['sha256']) && 1 === preg_match('#^[0-9a-f]{64}$#i', $e['sha256']))
                    ? strtolower($e['sha256']) : '',
            );
        }
        cache_set('store_plugins_v2', $out, 600);
        $plugins = $out;
        return $plugins;
    }

    /**
     * 按 dir 取单个商店插件条目（不存在返回 NULL）。
     */
    public static function storePlugin($dir)
    {
        foreach (self::storePlugins() as $e) {
            if (isset($e['dir']) && $e['dir'] === $dir) return $e;
        }
        return NULL;
    }

    /**
     * 解析插件的下载地址（**优先用商店下发的 `download_url`**）。
     *
     * 为何不再手工拼接：商店下发的形态是 `{base}/?api=download&type=plugin&dir=X`
     * （注意是 `/?api=download`，不是 `/index.php?api=...`），路径与查询串都可能变；
     * 手工拼等于把商店内部路由固化进客户端，商店一改就静默 404。
     *
     * 地址经 `CloudOpsService::storeUrl()` 归一（商店 site_url 与 cloud_api 主机不一致时改写到可达主机），
     * 再做 **http→https 升级**（仅当主机就是商店主机）：本站 `cloud_api` 已是 https，
     * 商店却常在 download_url 里写死 http://，明文下载安装包是不可接受的降级。
     * 非商店主机的地址一律原样保留（不越权改写）。
     *
     * @return string 可用下载地址；无法解析返回 ''
     */
    public static function storeDownloadUrl($dir, array $entry = array())
    {
        $url = isset($entry['download_url']) ? trim((string)$entry['download_url']) : '';
        if ('' === $url) {
            // 兜底：商店未下发 download_url 时按其**对外公布的 URL 形态**拼
            // （`{base}/?api=download&type=plugin&dir=X`；注意是 `/?api=` 段，
            //  不是 `/index.php?api=`——后者实测 404）。
            // 该兜底同样依赖商店已实现 download 端点；未实现时下载会以 http_error 明确失败。
            $base = self::storeBase();
            if ('' === $base) return '';
            $url = $base . '?api=download&type=plugin&dir=' . rawurlencode($dir);
        }
        $url = \App\Service\System\CloudOpsService::storeUrl($url);
        if ('' === $url || !preg_match('~^https?://~i', $url)) return '';

        $storeHost = strtolower((string)parse_url(self::storeBase(), PHP_URL_HOST));
        $uHost = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ('' !== $storeHost && $uHost === $storeHost && 0 === strpos(strtolower($url), 'http://')) {
            $url = 'https://' . substr($url, 7);
        }
        return $url;
    }

    /** 单插件可升级判定：商店存在该 dir 且版本高于本地 */
    public static function storeHasUpgrade($dir, $localVersion)
    {
        $entry = self::storePlugin($dir);
        if (NULL === $entry || '' === (string)$entry['version']) return FALSE;
        return -1 === version_compare((string)$localVersion, (string)$entry['version']) ? (string)$entry['version'] : FALSE;
    }

    /**
     * 从商店更新插件：取条目 → 下载（优先商店 download_url）→ 校验 → installFromZip(replace+keepFlags)
     * （更新已启用插件时保留 installed/enable 状态并重建编译缓存）。
     *
     * 版本校验：`software_version` 非空且高于本站版本时拒绝（商店侧声明的最低 CMS 版本）。
     * 完整性校验：商店下发 `sha256` 时**恒时比对**（`http_download_to_file` 在传输过程中
     * 已顺带算出哈希，无需回读磁盘）；商店未下发时跳过，由 HTTPS + 每跳 SSRF 校验兜底。
     */
    public static function storeUpdate($dir)
    {
        self::$lastError = '';
        if (!self::isValidDirName($dir)) return self::fail('目录名非法');
        $base = self::storeBase();
        if ('' === $base) return self::fail('未配置商店地址（cloud_api）');
        $entry = self::storePlugin($dir);
        if (NULL === $entry) return self::fail('商店不存在该插件或已下架');

        // 商店声明的软件版本要求（APP_VERSION 由 index.php 定义；CLI/安装器等
        // 不经该入口的场景回退读 conf.version，避免未定义常量）
        $need = (string)$entry['software_version'];
        if ('' !== $need) {
            $curVer = defined('APP_VERSION') ? (string)APP_VERSION : (string)(_SERVER('conf')['version'] ?? '');
            if ('' !== $curVer && version_compare($curVer, $need, '<')) {
                return self::fail('该插件要求 CMS 版本 ≥ ' . $need . '（当前 ' . $curVer . '）');
            }
        }

        $url = self::storeDownloadUrl($dir, $entry);
        if ('' === $url) return self::fail('无法解析插件下载地址（商店未下发 download_url）');

        $tmp = _SERVER('conf')['cache_path'] . 'store_' . $dir . '_' . mt_rand(100000, 999999) . '.zip';
        // 代码审查 #1 修复：流式下载（原 https_request 整包入内存；插件包可达数 MB~数十 MB，
        // 并发安装会顶 memory_limit）。http_download_to_file 直接落盘并在传输中校验 PK 前缀。
        $dl = http_download_to_file($url, $tmp, 200 * 1048576, 60, 5, 'PK');
        if (!$dl['ok']) {
            if (is_file($tmp)) @unlink($tmp);
            return self::fail('下载失败：' . $dl['error']);
        }
        // 包体哈希校验（商店已下发 sha256，2026-10-08 实测 4/4 与实际内容一致）。
        // 用 hash_equals 恒时比对防时序侧信道；商店未下发 sha256 时跳过（归一为 '' 即为该情形），
        // 此时完整性仍由 HTTPS + 每跳 SSRF 校验 + 商店主机白名单兜底。
        $expectSha = (string)$entry['sha256'];
        if ('' !== $expectSha && !hash_equals($expectSha, (string)$dl['sha256'])) {
            if (is_file($tmp)) @unlink($tmp);
            return self::fail('sha256 校验失败（防传输篡改/镜像不同步），已中止并清理临时包');
        }
        $r = self::installFromZip($tmp, TRUE, $dir, TRUE);
        if (is_file($tmp)) @unlink($tmp); // 成功时已被 rename 落位；失败时清理临时件
        if (FALSE === $r) return FALSE;
        return TRUE;
    }
}
