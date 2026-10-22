<?php
namespace App\Service\System;

class TemplateService
{
    /**
     * 主题/模板下载链统一错误日志：走 runtime/log 模板通道，吃统一脱敏与 10MB 覆盖策略。
     */
    private static function templateLogError(string $message): void
    {
        LoggerService::channel('template_error', LoggerService::LEVEL_ERROR, $message);
    }

    /**
     * 主题根目录的绝对文件系统路径（APP_PATH 即 web 根）。
     * @return string 以 / 结尾的目录路径（template/）
     */
    public static function templateRoot(): string
    {
        return \APP_PATH . 'template/';
    }

    /**
     * 当前启用主题目录名：读 KV 设置 conf.theme；缺失/为空一律回退 'default'（永不为空串）。
     * @return string 主题目录名（未做存在性校验，落盘解析在 themeBase 处 fail-closed）
     */
    public static function currentTheme(): string
    {
        $conf = setting_get('conf');
        $theme = is_array($conf) && !empty($conf['theme']) ? (string)$conf['theme'] : 'default';
        return $theme !== '' ? $theme : 'default';
    }

    /**
     * 当前主题目录的绝对基路径（realpath 解析、以单个 / 结尾）。
     * @return string themeBase(currentTheme())；目录不存在时 message(-1) 终止请求
     */
    public static function currentThemeBase(): string
    {
        return self::themeBase(self::currentTheme());
    }

    /**
     * 按主题名解析绝对基路径；$theme 传空串时回退当前主题。
     * @param string $theme 主题目录名
     * @return string 以 / 结尾的绝对路径；目录不存在时 message(-1) 终止请求
     */
    public static function themeBaseByName(string $theme = ''): string
    {
        $theme = $theme !== '' ? $theme : self::currentTheme();
        return self::themeBase($theme);
    }

    /**
     * 主题的模板编译源目录（<主题>/html/）——服务端编译源，nginx 已 deny 直读（防模板源码泄露）。
     * @param string $theme 主题目录名，空串=当前主题
     * @return string 以 / 结尾的绝对路径；主题目录不存在时 message(-1) 终止请求
     */
    public static function themeHtmlBase(string $theme = ''): string
    {
        return self::themeBaseByName($theme) . 'html/';
    }

    /**
     * 主题的前台资源 URL 前缀（非磁盘路径），供 index.inc.php 注入 $nncms['path_tpl'] 等模板变量。
     * @param string $theme 主题目录名，空/纯空白回退 'default'
     * @return string 形如 /template/<theme>/；⚠ 模板 {$var} 输出层不转义（§二.4），$theme 应只来自站点配置而非用户输入
     */
    public static function themePublicPath(string $theme = ''): string
    {
        $theme = $theme !== '' ? trim($theme) : self::currentTheme();
        $theme = $theme !== '' ? $theme : 'default';
        return '/template/' . $theme . '/';
    }

    /**
     * 会员区模板文件绝对路径：仅放行固定白名单（user_create/index/like/favorite/password.html）5 个文件。
     * 白名单外文件名直接 message(-1) 终止——这是防目录穿越/任意文件读取的入口闸。
     * @param string $filename 文件名（含 .html 后缀）
     * @return string template/user/html/<filename> 绝对路径
     */
    public static function userHtmlFile(string $filename): string
    {
        $filename = trim($filename);
        $allow = array(
            'user_create.html',
            'index.html',
            'like.html',
            'favorite.html',
            'password.html',
        );
        !in_array($filename, $allow, true) and message(-1, lang('data_malformation'));
        return self::templateRoot() . 'user/html/' . $filename;
    }

    /**
     * 默认主题（default）的模板编译源目录（html/），作为主题缺模板时的回退基路径。
     * @return string 以 / 结尾的绝对路径
     */
    public static function defaultHtmlBase(): string
    {
        return self::templateRoot() . 'default/html/';
    }

    /**
     * 默认主题 html/ 下的模板文件绝对路径（前台模板回退链的最后一级）。
     * @param string $filename 相对文件名（允许子目录如 user/login.html），先过 sanitizeRelativeTemplateFile 白名单，非法即 message(-1) 终止
     * @return string 绝对路径（不校验文件是否实际存在）
     */
    public static function defaultHtmlFile(string $filename): string
    {
        $filename = self::sanitizeRelativeTemplateFile($filename);
        return self::defaultHtmlBase() . $filename;
    }

    /**
     * 插件视图模板目录绝对路径（plugins/<dir>/view/html/）。
     * @param string $dir 插件目录名，先过 sanitizeRelativeDir 白名单（拒 ../NUL/字符集外），非法即 message(-1) 终止
     * @return string 以 / 结尾的路径
     */
    public static function addonViewHtmlBase(string $dir): string
    {
        $dir = self::sanitizeRelativeDir($dir);
        return \APP_PATH . 'plugins/' . $dir . '/view/html/';
    }

    /**
     * 插件视图 html/ 下模板文件绝对路径（dir 与 filename 各过对应白名单，非法即 message(-1) 终止）。
     * @param string $dir 插件目录名
     * @param string $filename 相对模板文件名
     * @return string 绝对路径（不校验存在性）
     */
    public static function addonViewHtmlFile(string $dir, string $filename): string
    {
        $filename = self::sanitizeRelativeTemplateFile($filename);
        return self::addonViewHtmlBase($dir) . $filename;
    }

    /**
     * 全部主题模板编译源的 glob 模式（供 view.php 模板预热/批量扫描枚举）。
     * @return string glob 模式串（非路径，勿直接当文件用）
     */
    public static function themeHtmlGlob(): string
    {
        return self::templateRoot() . '*/html/*.html';
    }

    /**
     * 前台视图类型 → 默认模板文件名的静态映射表（index/list/art_list/category/video/player/tag/search/message/404/user 系等 19 类）。
     * @param string $type 视图类型键（与路由 0 参同名，如 'index'、'user_login'）
     * @return string 映射内返回相对文件名；类型未知返回空串（调用方 resolveFrontViewFile 负责后续回退）
     */
    public static function frontViewDefaultFile(string $type): string
    {
        $map = array(
            'index' => 'index.html',
            'list' => 'list.html',
            'art_list' => 'art_list.html',
            'category' => 'category.html',
            'art_category' => 'art_category.html',
            'video' => 'video.html',
            'art' => 'art.html',
            'page' => 'page.html',   // category=2 单页（收口批次 A4）：view_load('page', $cid) 支持 <cid>_page.html 主题覆盖
            'player' => 'player.html',
            'tag' => 'tag.html',
            'tag_list' => 'tag_list.html',
            'type' => 'type.html',
            'search' => 'search.html',
            'message' => 'message.html',
            '404' => '404.html',
            'user_login' => 'user/login.html',
            'user_index' => 'user/index.html',
            'user_passwd' => 'user/passwd.html',
            'user-like' => 'user/like.html',
            'user-favorites' => 'user/favorites.html',
        );
        return isset($map[$type]) ? $map[$type] : '';
    }

    /**
     * 按模板模式与设备探测结果给模板文件名加设备前缀（core/model/view.php 的 view_load() 调用，detect=get_device()）。
     * $tplMode（后台设置）：0=自适应 1=PC和手机 2=PC、平板和手机；$detect：0=pc 1=wechat 2=pad 3=mobile。
     * 仅两者皆非 0 时生效：tplMode=2 且 detect=2 走 pad.<name>，其余非 0 组合统一走 m.<name>。
     * @param string $filename 基础模板文件名
     * @param int $tplMode 模板模式
     * @param int $detect 设备探测值
     * @return string 加前缀后的文件名；filename 为空串则原样返回空串
     */
    public static function devicePrefixedViewFile(string $filename, int $tplMode, int $detect): string
    {
        if ($filename === '') {
            return '';
        }
        if ($tplMode && $detect) {
            if (2 == $tplMode && 2 == $detect) {
                return 'pad.' . $filename;
            }
            return 'm.' . $filename;
        }
        return $filename;
    }

    /**
     * 前台模板解析链（view_load 的核心）：主题定制（<id>_<设备前缀名>，仅主题 conf.json
     * installed 非空时启用）→ 主题通用（<设备前缀名>）→ 兜底回退（$dir 非空取插件视图，
     * 否则取 default 主题），回退档用**不带设备前缀**的默认文件名。主题 conf 有请求级
     * static 缓存（按主题名）。
     * @param string $type 视图类型（经 frontViewDefaultFile 映射；未知则全链空文件名）
     * @param int $id 分类/文章定制模板 id（0 表示不启用 id 前缀）
     * @param string $dir 插件目录名（插件视图兜底用，空串=回退 default 主题）
     * @param string $theme 主题目录名，空串=当前主题
     * @param int $tplMode 模板模式 0/1/2（见 devicePrefixedViewFile）
     * @param int $detect 设备探测值 0..3（get_device()）
     * @return string 模板文件绝对路径（末级回退档**未校验 is_file**，文件可能不存在）
     */
    public static function resolveFrontViewFile(string $type, int $id = 0, string $dir = '', string $theme = '', int $tplMode = 0, int $detect = 0): string
    {
        $theme = $theme !== '' ? $theme : self::currentTheme();
        $defaultPre = self::frontViewDefaultFile($type);
        $pre = self::devicePrefixedViewFile($defaultPre, $tplMode, $detect);
        static $confCache = array();
        if (!isset($confCache[$theme])) {
            $confCache[$theme] = self::themeConfig($theme);
        }
        $json = $confCache[$theme];
        $themeHtmlBase = self::themeHtmlBase($theme);

        $pathFile = '';
        if (!empty($json['installed'])) {
            $pathFile = $themeHtmlBase . ($id ? $id . '_' : '') . $pre;
        }
        if (empty($pathFile) || !is_file($pathFile)) {
            $pathFile = $themeHtmlBase . $pre;
        }
        if (!is_file($pathFile)) {
            $pathFile = $dir !== ''
                ? self::addonViewHtmlFile($dir, $defaultPre)
                : self::defaultHtmlFile($defaultPre);
        }
        return $pathFile;
    }

    /**
     * 读取主题 conf.json 清单（enable/installed/version 等，展示层键映射见 .skills/插件开发.md）。
     * @param string $theme 主题目录名，空串=当前主题
     * @return array 清单数组；文件缺失或 JSON 解析失败返回空数组（fail-safe 读侧，不报错）
     */
    public static function themeConfig(string $theme = ''): array
    {
        $theme = $theme !== '' ? $theme : self::currentTheme();
        $conffile = self::themeBase($theme) . 'conf.json';
        return is_file($conffile) ? (array)xn_json_decode(file_get_contents($conffile)) : array();
    }

    /**
     * 扫描 template/ 下全部主题目录并读 conf.json（缺文件/解析空跳过），构造主题注册表
     * （R3-2 起主题域唯一权威；AddonService 旧主题注册表已删）。
     * @return array 含 tmpls / tmpllist 两键（目录名 => 清单数组）。当前两键内容**完全相同**
     *         （历史区分口径已统一），templateList 的 type=2/3 分别取 tmpllist/tmpls
     */
    public static function loadState(): array
    {
        $tmpls = array();
        $tmpllist = array();
        $tmplPaths = glob(self::templateRoot() . '*', GLOB_ONLYDIR);
        if (is_array($tmplPaths)) {
            foreach ($tmplPaths as $path) {
                $dir = file_name($path);
                $conffile = $path . '/conf.json';
                if (!is_file($conffile)) continue;
                $arr = xn_json_decode(file_get_contents($conffile));
                if (empty($arr)) continue;
                $tmpls[$dir] = $arr;
                $tmpllist[$dir] = $arr;
            }
        }

        return array(
            'tmpls' => $tmpls,
            'tmpllist' => $tmpllist,
        );
    }

    /**
     * 主题列表（内存态，来自 loadState，非 SQL）：二维数组条件过滤 + 排序 + 分页
     * （arrlist_cond_orderby，$cond/$orderby 语义同后台其它列表页）。
     * @param array $cond 过滤条件
     * @param array $orderby 排序规则
     * @param int $page 页码
     * @param int $pagesize 每页条数（后台模板页传 500）
     * @param int $type 2=读 tmpllist；3=读 tmpls（当前两者内容相同）；**其余值（含默认 1）恒返回空数组**
     * @return array 分页后的主题清单
     */
    public static function templateList(array $cond = array(), array $orderby = array(), int $page = 1, int $pagesize = 20, int $type = 1): array
    {
        $state = self::loadState();
        if (2 != $type && 3 != $type) return array();
        $list = 2 == $type ? $state['tmpllist'] : $state['tmpls'];
        // 「已安装」以站点当前启用主题（KV conf.theme，currentTheme）为唯一口径：
        // 各主题 conf.json 的 installed 标志会漂移（商店包自带 installed=1、切换主题时旧主题未必被置 0），
        // 直接读它会让后台出现多个「卸载」。这里只覆盖展示数据，不改写 conf.json。
        $active = self::currentTheme();
        foreach ($list as $dir => $row) {
            $list[$dir]['installed'] = ((string)$dir === $active) ? 1 : 0;
        }
        return arrlist_cond_orderby($list, $cond, $orderby, $page, $pagesize);
    }

    /**
     * 本地可用的主题目录名列表（有 conf.json 且可解析），CloudOpsService 做云端/本地比对时取交集用。
     * @return string[] 目录名数组
     */
    public static function availableDirs(): array
    {
        $state = self::loadState();
        return array_keys($state['tmpllist']);
    }

    /**
     * 安装/启用主题：conf.json 置 installed=1 → 写 KV 设置 conf.theme（切换站点主题）→
     * 旧启用主题先走 uninstall → 执行主题 install.php（M-A4：先过 themeExecHookSafe 内容闸，
     * 被拒即跳过执行并留痕 theme_hook_error，fail-closed）→ 清空编译缓存。
     * 前置校验失败（conf.json 缺失/解析为空）经 message(1,...) 渲染提示页终止。
     * @param string $dir 主题目录名；本函数不做字符集校验，调用方（TmplManageService::toggleInstall）以 param_word 取参白名单化
     * @return true 恒为 TRUE（除非中途 message 终止）
     */
    public static function install(string $dir)
    {
        $conf = _SERVER('conf');         global $config;

        $dir = trim($dir);
        !empty($config['theme']) and $config['theme'] != $dir and self::uninstall($config['theme']);
        $path = self::templateRoot() . $dir;
        $conffile = $path . '/conf.json';
        !is_file($conffile) and message(1, lang('not_exists'));
        $arr = xn_json_decode(file_get_contents($conffile));
        empty($arr) and message(1, lang('data_malformation'));
        $arr['installed'] = 1;
        file_replace_var($conffile, $arr, TRUE);
        $config['theme'] = $dir;
        setting_set('conf', $config);
        $installfile = $path . '/install.php';
        // 上线前审计修复（M-A4）：主题包来自商店/第三方，install.php 是 download 闸唯一放行的可执行
        // php 条目（「安装→卸载」两步即可任意代码执行）——执行前高危构造闸，拒绝则跳过并记日志（fail-closed）。
        if (is_file($installfile) && self::themeExecHookSafe((string)file_get_contents($installfile), $dir)) {
            include _include($installfile);
        }
        // 精确清编译产物（AddonService::clearTmpDir 跳过点文件与 lock_*）：rmdir_recusive($cache_path)
        // 会连坐删掉 .breaker_state.php 等跨请求状态，见该方法 docblock。
        \App\Service\System\AddonService::clearTmpDir($conf['cache_path']);
        return TRUE;
    }

    /**
     * 卸载/停用主题：conf.json 置 installed=0 → 清空 KV 设置 conf.theme → 执行主题
     * uninstall.php（同 install() 的 M-A4 内容闸口径，被拒即跳过并留痕）→ 清空编译缓存。
     * 前置校验失败（conf.json 缺失/解析为空）经 message(1,...) 终止。
     * @param string $dir 主题目录名
     * @return true 恒为 TRUE（除非中途 message 终止）
     */
    public static function uninstall(string $dir)
    {
        $conf = _SERVER('conf');         global $config;

        $path = self::templateRoot() . $dir;
        $conffile = $path . '/conf.json';
        FALSE === is_file($conffile) and message(1, lang('not_exists'));
        $arr = xn_json_decode(file_get_contents($conffile));
        empty($arr) and message(1, lang('data_malformation'));
        $arr['installed'] = 0;
        file_replace_var($conffile, $arr, TRUE);
        $config['theme'] = '';
        setting_set('conf', $config);
        $uninstallfile = $path . '/uninstall.php';
        // 上线前审计修复（M-A4）：与 install.php 同口径——主题卸载钩子执行前高危构造闸，
        // 拒绝则跳过执行并记日志（kv_*/db_* 等清理类钩子不受影响）。
        if (is_file($uninstallfile) && self::themeExecHookSafe((string)file_get_contents($uninstallfile), $dir)) {
            include _include($uninstallfile);
        }
        \App\Service\System\AddonService::clearTmpDir($conf['cache_path']);
        return TRUE;
    }

    /**
     * 上线前审计修复（M-A4）：主题 install.php/uninstall.php 执行前高危构造闸。
     * 主题包经商店/第三方渠道分发，钩子脚本是 download 闸唯一放行的可执行 php 条目；
     * 本闸按 fail-closed 拒绝代码执行/进程执行/文件系统写删/动态包含/编码混淆/变量变量/超全局直读等
     * WebShell 级原语，kv_ 与 db_ 前缀的数据清理类钩子照常放行。
     * @return bool true=可执行；false=拒绝
     */
    private static function themeExecHookSafe(string $code, string $dir): bool
    {
        if ('' === $code) return TRUE;
        // 无 php 开标签 = 无可执行面
        if (!str_contains($code, '<?')) return TRUE;
        if (strlen($code) > 65536) {
            LoggerService::channel('theme_hook_error', LoggerService::LEVEL_ERROR, 'theme exec hook too large rejected: ' . $dir);
            return FALSE;
        }
        $lower = strtolower($code);
        // 黑名单：函数调用形态 + 变量变量/超全局/反引号（词边界防属性名误伤）
        // 发布前检查修复（B-2）：黑名单按编辑器 safeEditableForbiddenFuncs 口径对齐——补回调原语
        // （ob_start/array_walk 族/iterator_apply/register_*/set_*_handler/preg_replace_callback 族/
        // forward_static_call 族/Closure::fromCallable）与解码原语，堵「回调传入高危函数名」绕过（清单见 template_guard.json）。
        $g = self::guardData();
        if (null === $g) {
            LoggerService::channel('theme_hook_error', LoggerService::LEVEL_ERROR, 'theme exec hook rejected (template guard data unavailable): ' . $dir);
            return FALSE;
        }
        $forbidden = '#\\b(' . $g['hook_alt'] . ')\\s*\\(#i';
        if (preg_match($forbidden, $lower)) {
            LoggerService::channel('theme_hook_error', LoggerService::LEVEL_ERROR, 'theme exec hook forbidden construct rejected: ' . $dir);
            return FALSE;
        }
        // 发布前检查修复（B-2）：补变量函数调用形态 $\w+\s*\(（编辑器 legacyRegexFindings:440 同口径），
        // 防 $f='sys'.'tem'; $f('id'); 全程不命中黑名单。
        if (preg_match('#(\$\$|\$_(GET|POST|REQUEST|FILES|SERVER|ENV|COOKIE)\b|`|\binclude\s*\$|\beval\s*\$|\$\w+\s*\()#i', $code)) {
            LoggerService::channel('theme_hook_error', LoggerService::LEVEL_ERROR, 'theme exec hook dynamic construct rejected: ' . $dir);
            return FALSE;
        }
        // 本轮审计修复：词法层兜底（token 级）——覆盖正则易漏的形态：注释分隔的调用
        // （变量与括号间夹注释）、括号/数组元素直接调用、PHP8 全限定函数名
        // （\名字(参数)）等。与模板编辑/主题内容闸同一 scanCode 口径（fail-closed：命中即跳过执行并留痕）。
        if (!empty(\App\Service\System\LexicalSafetyService::scanCode($code))) {
            LoggerService::channel('theme_hook_error', LoggerService::LEVEL_ERROR, 'theme exec hook lexical violation rejected: ' . $dir);
            return FALSE;
        }
        return TRUE;
    }

    /**
     * 云端主题包下载 + 解包（模板商店链路的落盘闸，全程 fail-closed）：
     * URL 经 CloudOpsService::cloudUrl 归一 → http_download_to_file 流式下载（200MB 传输上限，
     * 临时文件+rename，内存峰值与包体无关）→ ZipArchive 打开 → 结构闸 validateUpgradeZip
     * （路径合法/拒可执行后缀与软链）→ 条目数 ≤5000、总解压体积 ≤1GB（防 zip 炸弹，N-05）→
     * 逐项内容闸：危险扩展名清单 + 以 .ht / .user 起始的点文件拒（唯一 PHP 例外=主题根 <dir>/uninstall.php，
     * 且**入包即过** themeExecHookSafe，N-02 消除「先落盘后被拒」窗口；zip 根层 uninstall.php
     * 例外已按 B-5 移除）→ .html/.htm 条目过模板内容闸（B-1：legacyRegexFindings +
     * LexicalSafetyService::scanTemplate，防 {if:EXPR} 指令借下载链进渲染期）→ 软链条目拒 →
     * 条目归属闸（VULN-H06：每项必须落 template/<dir>/ 之下，防不激活主题即覆盖他主题文件）。
     * 任一闸命中即关包删临时 zip 返回 FALSE 并 error_log；全过后 extractTo 落盘并删包。
     * @param string $url 云端下载地址
     * @param string $dir 主题目录名（basename 归一后同时用作归属前缀与 uninstall.php 例外判定）
     * @return bool TRUE=解包成功；FALSE=任一环节失败；解包后 conf.json 缺失则 message(-1) 终止
     */
    public static function download(string $url, string $dir)
    {
        $dir = basename($dir);
        // PHP 未启用 zip 扩展：ZipArchive 不存在，new 即 Fatal。先拦，提示站长启用扩展
        if (!class_exists('ZipArchive')) {
            self::templateLogError('ZipArchive error: PHP zip extension not loaded');
            return false;
        }
        $savePath = \APP_PATH . 'runtime/cache/' . $dir . '.zip';
        // 修复：商店 downurl 是**明文**（且主机可能是商店 site_url，需归一为可达地址），
        // 原先的 core_decrypt 在 store 模式下返空 ⇒ 主题包下载必然失败。统一走 cloudUrl()。
        $downurl = \App\Service\System\CloudOpsService::cloudUrl($url);
        if ('' === $downurl) {
            self::templateLogError('ZipArchive error: unusable download url');
            return false;
        }
        // 代码审查 #1 修复：改为**流式下载**（原先 https_request 整包入内存后才 strlen 判 200MB，
        // 上限在内存维度无效——判超限时 200MB 已驻留 PHP 内存；且 fwrite(fopen()) 无错误检查，
        // 写盘失败会静默留空文件，把「不是 zip」伪装成下载成功）。
        // 现由 http_download_to_file 在传输过程中按块累计、超限即中止，内存峰值与包体无关；
        // 写临时文件 + 成功后 rename，失败路径不留半截包。
        $dl = http_download_to_file($downurl, $savePath, 200 * 1048576, 60, 5, '');
        if (!$dl['ok']) {
            self::templateLogError('ZipArchive error: download failed (' . $dl['error'] . ', ' . $dl['bytes'] . 'B) ' . $downurl);
            return false;
        }
        $destpath = self::templateRoot();
        is_dir($destpath) || mkdir($destpath, 0777, TRUE);
        $zip = new \ZipArchive();
        if ($zip->open($savePath) !== TRUE) {
            self::templateLogError('ZipArchive error: cannot open ' . $savePath);
            return false;
        }
        // 解包策略对齐升级链（CloudOpsService::validateUpgradeZip 路径校验）并加模板面专属红线：
        // 拒绝可执行后缀/软链条目——主题包落 template/ 即处于 docroot，.php 条目直出=RCE
        if (!\App\Service\System\CloudOpsService::validateUpgradeZip($zip)) {
            $zip->close();
            @unlink($savePath);
            self::templateLogError('ZipArchive error: illegal path in ' . $savePath);
            return false;
        }
        // N-05（上线前审计）：补齐升级链同款 zip 上限（条目数 5000 / 总解压体积 1GB，防炸弹）
        if ($zip->numFiles > 5000) {
            $zip->close();
            @unlink($savePath);
            self::templateLogError('ZipArchive error: too many entries in ' . $savePath);
            return false;
        }
        $totalUncompressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $capStat = $zip->statIndex($i);
            if ($capStat === false) continue;
            $totalUncompressed += intval($capStat['size']);
            if ($totalUncompressed > 1024 * 1048576) {
                $zip->close();
                @unlink($savePath);
                self::templateLogError('ZipArchive error: total uncompressed size exceeds limit in ' . $savePath);
                return false;
            }
        }
        $dangerExt = array('php', 'phar', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'pht', 'pl', 'py', 'cgi', 'sh', 'exe', 'jsp', 'asp', 'aspx');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            $norm = strtolower(str_replace('\\', '/', $entry));
            $base = basename($norm);
            $ext = pathinfo($norm, PATHINFO_EXTENSION);
            // 唯一 PHP 例外：主题根目录 uninstall.php（TemplateService::uninstall 既有约定文件）；任何子层的 php 仍拒。
            // N-05（上线前审计）：原例外 substr_count<=1 未校验目录归属，`<其他目录>/uninstall.php`
            // 可落 template/ 任意子目录（docroot 可执行面）——收紧为仅放行主题根约定文件。
            $dirNorm = strtolower((string)$dir);
            // 发布前检查修复（B-5）：移除 zip 根层 uninstall.php 例外——根条目解压落 template/uninstall.php
            // （主题目录之外），install/uninstall 恒只消费 template/<dir>/uninstall.php，根条目永不被合法执行，
            // 只会扩大 docroot 直执行面（且不经 themeExecHookSafe 闸）。
            $isThemeUninstallHook = ($norm === $dirNorm . '/uninstall.php');
            if (!$isThemeUninstallHook && (in_array($ext, $dangerExt, TRUE) || str_starts_with($base, '.ht') || str_starts_with($base, '.user'))) {
                $zip->close();
                @unlink($savePath);
                self::templateLogError('ZipArchive error: executable entry blocked: ' . $entry);
                return false;
            }
            // 上线前终检修复（N-02，High）：主题钩子是解包期**唯一**放行的 .php，而原实现只看条目名、
            // **不看内容**——`themeExecHookSafe()` 只在 uninstall() **执行**时才跑，于是"先落盘、后被拒"
            // 之间留了窗口：任何能驱动 download() 的路径（如修复前的 `{if:…::download(...)}` 静态调用闸绕过）
            // 都能把 `template/<dir>/uninstall.php`（内容为一句话后门）写进 docroot。
            // 现改为**入包即过闸**：内容不合法 ⇒ 整包拒绝、不落盘（与执行期同一把尺子，fail-closed）。
            if ($isThemeUninstallHook) {
                $hookCode = (string)$zip->getFromIndex($i);
                if (!self::themeExecHookSafe($hookCode, $dir)) {
                    $zip->close();
                    @unlink($savePath);
                    self::templateLogError('ZipArchive error: theme uninstall hook rejected by content gate: ' . $entry);
                    return false;
                }
            }
            // 软链条目（external attrs 高 16 位 S_IFLNK）：可把 extractTo 写成指向任意位置的链接
            $stat = $zip->statIndex($i);
            if (is_array($stat) && isset($stat['external']) && (((intval($stat['external']) >> 16) & 0120000) === 0120000)) {
                $zip->close();
                @unlink($savePath);
                self::templateLogError('ZipArchive error: symlink entry blocked: ' . $entry);
                return false;
            }
            // 发布前检查修复（B-1 High）：.html/.htm 条目解压前过与模板编辑器同口径的内容闸。
            // {if:EXPR} 表达式经 view.php process_if 原样拼进编译产物（「保存期校验是唯一防线」），
            // 下载链此前只查结构不查内容——恶意主题包可经模板指令在渲染期任意代码执行。
            if (in_array($ext, array('html', 'htm'), TRUE)) {
                $entryCode = $zip->getFromIndex($i);
                if (is_string($entryCode) && '' !== $entryCode) {
                    $gateFindings = self::legacyRegexFindings($entryCode);
                    if (empty($gateFindings)) {
                        $gateFindings = LexicalSafetyService::scanTemplate($entryCode);
                    }
                    if (!empty($gateFindings)) {
                        $zip->close();
                        @unlink($savePath);
                        $gateFirst = $gateFindings[0];
                        $gateReason = is_array($gateFirst) ? ((string)($gateFirst['kind'] ?? '') . ' ' . (string)($gateFirst['name'] ?? '')) : (string)$gateFirst;
                        self::templateLogError('ZipArchive error: template content gate rejected entry ' . $entry . ' (' . $gateReason . ')');
                        return false;
                    }
                }
            }
        }
        // 上线前审计修复（VULN-H06）：**条目归属闸**。
        // 原实现直接 `extractTo($destpath)`（EXTR_OVERWRITE），条目路径只要不逃出站点根即可，
        // 于是恶意主题包可在**不激活主题**的情况下覆盖 `template/default/html/*`、
        // `template/<其它主题>/*` 等既有文件（同名即覆盖）。现要求每个条目必须落在
        // 本包自己的主题目录 `template/<dir>/` 之下；目录条目（以 / 结尾）同样受限。
        // 注意与「软链/可执行后缀/炸弹」三道既有闸叠加，不替代它们。
        $dirPrefix = $dir . '/';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string)$zip->getNameIndex($i);
            $norm = str_replace('\\', '/', $entry);
            // 去掉可能的 ./ 前缀后再比对归属（validateUpgradeZip 已拒 `..` 段）
            while (str_starts_with($norm, './')) $norm = substr($norm, 2);
            if ('' === $norm || str_starts_with($norm, '/')) {
                $zip->close();
                @unlink($savePath);
                self::templateLogError('ZipArchive error: bad entry path blocked: ' . $entry);
                return false;
            }
            if (0 !== strpos($norm, $dirPrefix)) {
                $zip->close();
                @unlink($savePath);
                self::templateLogError('ZipArchive error: entry outside theme dir blocked: ' . $entry);
                return false;
            }
        }
        $zip->extractTo($destpath);
        $zip->close();
        if (is_file($savePath)) unlink($savePath);
        $conffile = $destpath . $dir . '/conf.json';
        !is_file($conffile) and message(-1, 'conf.json ' . lang('not_exists'));
        return TRUE;
    }

    /**
     * 模板安全规则数据文件（相对 core/ 目录）。
     * 高危关键字 / 高危函数 / 主题钩子禁用构造这三份黑名单原先以字面量写在本文件里，
     * 密集的高危函数名会让主机杀毒软件（Imunify360、ClamAV 启发式等）把本文件误判为后门并隔离，
     * 结果全站 500。现改存 core/config/template_guard.json（逐项 hex 编码），本文件只做加载与校验。
     * 数据文件缺失/损坏/摘要不符 ⇒ guardAvailable()=false，所有使用方 fail-closed（拒绝，不放行）。
     */
    public const GUARD_DATA_REL = 'config/template_guard.json';
    /** 规则内容摘要：sha256(keywords\n…\n\nfuncs\n…\n\nhook_alt)，与数据文件不一致即视为损坏（修改清单的步骤见 README「模板安全规则数据」：数据文件与本常量须同时更新） */
    private const GUARD_DATA_SHA256 = 'd07abb2f8eb631559e6749381165f6a8aa43f252d49dc6a7bd8b7eb7ce22fc49';
    /** @var array|false|null 解码后的规则（null=未加载，false=不可用） */
    private static $guardData = null;

    /** 数据文件绝对路径（不依赖 APP_PATH：本文件位于 core/app/Service/System/） */
    public static function guardDataFile(): string
    {
        return dirname(__DIR__, 3) . '/' . self::GUARD_DATA_REL;
    }

    /**
     * 加载并校验规则数据（进程内缓存）。
     * @return array|null array('keywords'=>string[], 'funcs'=>string[], 'hook_alt'=>string)；不可用返回 null
     */
    private static function guardData(): ?array
    {
        if (null === self::$guardData) {
            self::$guardData = false;
            $file = self::guardDataFile();
            $raw = is_file($file) ? @file_get_contents($file) : false;
            $j = is_string($raw) ? json_decode($raw, true) : null;
            $dec = static function ($hex) {
                if (!is_string($hex) || '' === $hex || !preg_match('/^(?:[0-9a-f]{2})+$/', $hex)) return null;
                return (string)pack('H*', $hex);
            };
            $ok = is_array($j) && ($j['format'] ?? '') === 'nncms-template-guard' && (int)($j['version'] ?? 0) === 1
                && is_array($j['keywords'] ?? null) && is_array($j['funcs'] ?? null) && is_string($j['hook_alt'] ?? null);
            if ($ok) {
                $kw = array_map($dec, $j['keywords']);
                $fn = array_map($dec, $j['funcs']);
                $hook = $dec($j['hook_alt']);
                $ok = !in_array(null, $kw, true) && !in_array(null, $fn, true) && null !== $hook && $kw && $fn
                    && hash('sha256', implode("\n", $kw) . "\n\n" . implode("\n", $fn) . "\n\n" . $hook) === self::GUARD_DATA_SHA256;
                if ($ok) self::$guardData = array('keywords' => $kw, 'funcs' => $fn, 'hook_alt' => $hook);
            }
            if (!self::$guardData) {
                self::templateLogError('template guard data unavailable (missing/corrupt): ' . self::GUARD_DATA_REL);
            }
        }
        return self::$guardData ?: null;
    }

    /** 规则数据是否可用（不可用时一切模板/钩子安全闸按拒绝处理） */
    public static function guardAvailable(): bool
    {
        return null !== self::guardData();
    }

    /** 规则缺失时给站长看的统一文案 */
    public static function guardMissingMessage(): string
    {
        return '模板安全规则文件缺失或已损坏（core/' . self::GUARD_DATA_REL . '），已按安全策略拒绝该模板内容。'
            . '请从安装包重新上传该文件（如被主机安全软件隔离，请恢复并加入白名单）。';
    }

    /** 渲染期（{inc:} / <template include>）规则缺失时输出的可见提示片段 */
    public static function guardMissingHtml(): string
    {
        return '<div style="margin:8px;padding:8px 12px;border:1px solid #e0b4b4;background:#fff6f6;color:#9f3a38;font-size:13px">'
            . htmlspecialchars(self::guardMissingMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    }

    /** @var array 语句型关键字（词边界匹配；词法层共用）。数据见 template_guard.json；不可用返回空数组（调用方须先判 guardAvailable） */
    public static function safeEditableForbiddenKeywords(): array
    {
        $g = self::guardData();
        return $g ? $g['keywords'] : array();
    }

    /** @var array 高危函数清单（调用形态匹配；词法层共用）。数据见 template_guard.json；不可用返回空数组（调用方须先判 guardAvailable） */
    public static function safeEditableForbiddenFuncs(): array
    {
        $g = self::guardData();
        return $g ? $g['funcs'] : array();
    }

    /**
     * 审计修复：模板指令表达式（{if:}/{elseif:}/{loop:}/{$...}）的**函数调用白名单**。
     * 黑名单在「回调函数 + 引用函数名作字符串」面前是打地鼠；此白名单覆盖真实模板语料
     * （仅 defined/function_exists/group_access/count 等）与常用只读函数，凡不在名单内的
     * 普通函数调用一律 fail-closed 拒绝。成员/静态/构造调用不受本名单约束（另有反射/动态调用闸）。
     */
    public static function safeTemplateCallAllowlist(): array
    {
        return array(
            // 语言/数组判定与读取
            'count', 'sizeof', 'in_array', 'array_key_exists', 'array_keys', 'array_values',
            'array_merge', 'array_slice', 'array_reverse', 'array_search', 'array_sum',
            'array_unique', 'array_column', 'array_combine', 'array_flip', 'array_pad',
            'array_shift', 'array_unshift', 'array_pop', 'array_push', 'array_fill',
            'sort', 'rsort', 'asort', 'arsort', 'ksort', 'krsort',
            'is_array', 'is_string', 'is_int', 'is_integer', 'is_float', 'is_double',
            'is_numeric', 'is_null', 'is_bool', 'is_object', 'is_callable', 'is_iterable',
            'is_scalar', 'is_resource',
            // 字符串
            'strpos', 'stripos', 'strrpos', 'strripos', 'strstr', 'stristr',
            'strlen', 'mb_strlen', 'mb_substr', 'mb_strpos', 'mb_strtolower', 'mb_strtoupper',
            'substr', 'substr_count', 'str_replace', 'str_ireplace', 'str_repeat', 'str_pad',
            'trim', 'ltrim', 'rtrim', 'strtolower', 'strtoupper', 'ucfirst', 'ucwords', 'lcfirst',
            'explode', 'implode', 'join', 'sprintf', 'vsprintf', 'number_format', 'nl2br',
            'wordwrap', 'str_split', 'chunk_split', 'addslashes', 'stripslashes',
            'htmlspecialchars', 'htmlspecialchars_decode', 'html_entity_decode',
            'urlencode', 'urldecode', 'rawurlencode', 'rawurldecode', 'http_build_query',
            'json_encode', 'json_decode',
            // 数值/时间
            'round', 'floor', 'ceil', 'abs', 'max', 'min', 'intval', 'floatval', 'doubleval',
            'strval', 'boolval', 'pow', 'sqrt', 'fmod',
            'date', 'time', 'mktime', 'strtotime', 'gmdate', 'checkdate',
            'preg_match', 'preg_match_all', 'preg_split', 'preg_quote',
            // 环境/框架上下文读取（只读，无执行面）
            'defined', 'function_exists', 'class_exists', 'method_exists', 'property_exists',
            'extension_loaded', 'group_access', 'group_name', 'lang', 'url', 'param',
            '_GET', '_POST', '_REQUEST', '_COOKIE', '_SESSION', '_SERVER', 'G',
            'array_value', 'arrlist_key_values', 'findcate', 'setting_get', 'user_read',
            // 上线前审计修复（I-01 配套）：`{@EXPR}` 纳入表达式扫描后，默认主题
            // public/foot.html:45 的 `{@xn_debug_info()}`（DEBUG 1–2 调试信息块）会命中白名单。
            // 该调用无参数、只读、无执行面，且仅在 DEBUG<3 时渲染 ⇒ 登记为合法。
            'xn_debug_info',
        );
    }

    /**
     * 后台模板编辑保存前的安全闸（配合 TmplManageService::saveFile）：
     * 词法层 LexicalSafetyService::scanTemplate **先行**（VULN-C01/C03：词法判定更精确，首条文案由它给），
     * 再走 legacyRegexFindings 正则五层兜底；两层均 fail-closed——命中即 message(0,...) + exit 终止请求。
     * ⚠ 必要性：模板 {if:EXPR}/{@EXPR}/{$...} 指令原样编译进 PHP（tpl_allow_php/tpl_allow_expr 是
     * RCE 级开关、出厂为 0），保存期校验是唯一防线；不要改成"仅告警放行"。
     * @param string $code 待保存的模板全文（已 html_entity_decode）
     * @return true 全部通过才可达（失败分支在函数内 exit）
     */
    public static function assertSafeEditableCode(string $code)
    {
        // 正则五层（历史口径）+ L1 词法层（并行，任务 2）
        //
        // 上线前审计（VULN-C01/C03）调整**判定顺序**：词法层先行。
        // 原因：本轮为正则层新增了 new/use/clone 表达式禁令（堵 VULN-C01 的
        // `new <内置类>->方法()` 逃逸），这使 `{if:(new ReflectionFunction("<高危函数名>"))->invoke()}` 这类
        // 载荷会被正则层以「禁止对象实例化」拦下，掩盖词法层**独立**识别出的 reflection 语义。
        // 词法层是更精确的判定（token 级、能报出具体危险构造），故让它先报，正则层退为兜底；
        // 两层都是 fail-closed，拦截结论不变（仅影响「首条文案」由哪一层给出）。
        if (!self::guardAvailable()) {
            exit(message(0, self::guardMissingMessage()));
        }
        $lexFindings = LexicalSafetyService::scanTemplate($code);
        if (!empty($lexFindings)) {
            $first = $lexFindings[0];
            exit(message(0, '模板词法校验未通过：' . $first['kind'] . ' ' . $first['name']));
        }
        $legacy = self::legacyRegexFindings($code);
        if (!empty($legacy)) exit(message(0, $legacy[0]));
        return TRUE;
    }

    /**
     * 正则五层校验（历史口径，结构化返回以便与 L1 词法层做独立对比）：
     * 符号禁令 / 语句型关键字 / include·require 指令形态 / 高危函数调用形态 / 指令表达式深扫
     * @return string[] 每项为可直接用于 message() 的拒绝文案（空数组=通过）
     */
    public static function legacyRegexFindings(string $code): array
    {
        $findings = array();
        if (!self::guardAvailable()) {
            $findings[] = self::guardMissingMessage();
            return $findings;
        }
        $forbiddenKeywords = self::safeEditableForbiddenKeywords();
        // include/require 是语句型（可无括号），按「指令+操作数」形态匹配而非裸词边界——
        // 现有模板大量合法的 <template include="…"> 属性与注释文本不得误伤（实测 27/48 误拒修复）
        $stmtPattern = '#\b(?:include|require)(?:_once)?\b\s*[\($"\'\\\\$]#i';
        // 函数名类关键字改为「调用形态」匹配（name\s*\(），消除 HTML/CSS/JS 属性词误伤：
        // 实测 corpus 中 system-ui 字体栈 / style=…filter:…/require、SCSS 的 include 指令等 27/48 现有模板均被
        // 旧词边界匹配误拒（保存即被禁 = 功能破坏）。控制结构词（语句型关键字清单 + include/require）
        // 仍按词边界，防「include $x;」等无括号形态。
        $forbiddenFuncs = self::safeEditableForbiddenFuncs();
        $forbiddenSymbols = array('<?', '{:', '{~', '{-', '{+');

        $codeLower = strtolower($code);
        foreach ($forbiddenSymbols as $symbol) {
            if (str_contains($codeLower, $symbol)) {
                $findings[] = '模板中包含违规符号 ' . $symbol . ' 禁止编辑';
                return $findings;
            }
        }

        $pattern = '/\b(' . implode('|', array_map('preg_quote', array_unique($forbiddenKeywords))) . ')\b/i';
        if (preg_match($pattern, $codeLower, $matches)) {
            $findings[] = '模板中包含高危函数/关键字 ' . $matches[1] . ' 禁止编辑';
            return $findings;
        }
        if (preg_match($stmtPattern, $codeLower)) {
            $findings[] = '模板中包含 PHP 包含指令形态，禁止编辑';
            return $findings;
        }
        $funcPattern = '/\b(' . implode('|', array_map('preg_quote', array_unique($forbiddenFuncs))) . ')\s*\(/i';
        if (preg_match($funcPattern, $codeLower, $fmatches)) {
            $findings[] = '模板中包含高危函数 ' . $fmatches[1] . ' 禁止编辑';
            return $findings;
        }

        // 指令表达式深扫：{if:EXPR}/{loop:EXPR}/{php:}/{vars:} 与 {$...} 内容原样进 PHP。
        // 反引号/${/$_/变量函数只在此语境禁止（HTML/JS 模板字符串的 ` 与 ${} 在指令外属合法形态）。
        $exprHits = array();
        // 审计修复：补 elseif（process_if 归并处理 {elseif:EXPR}，此前两层都只扫 if）
        if (preg_match_all('#\{(?:if|elseif|loop|php|vars):(.*?)\}#is', $code, $mm)) {
            $exprHits = array_merge($exprHits, $mm[1]);
        }
        // 上线前审计修复（I-01）：`{@EXPR}` 被 view.php 的 tpl_allow_expr 分支编译成一条 php echo 语句，
        // 而原提取正则只覆盖 `name:` 形态 ⇒ 该指令完全不在扫描面内
        // （实测 `{@$f="sys"."tem"}{@$f("id")}`、``{@`id`}`` 均通过）。线上 tpl_allow_expr=0
        // 时被剥离，属纵深；与 KV 写原语叠加即为 RCE。
        if (preg_match_all('#\{@([^}]*)\}#s', $code, $mm3)) {
            $exprHits = array_merge($exprHits, $mm3[1]);
        }
        if (preg_match_all('#\{\$([^{}]*)\}#s', $code, $mm2)) {
            $exprHits = array_merge($exprHits, $mm2[1]);
        }
        // 上线前审计修复（VULN-C01/C03）：`new` / `use` 导入 / `clone` / 匿名函数
        // 在模板指令表达式中一律禁止。独立于函数名黑名单，故对拼接、use 别名、全限定类名
        // （\PharData、Foo\Bar）等形态同样生效——这些正是绕过原白名单的载体。
        foreach ($exprHits as $expr) {
            if (preg_match('#\bnew\s|\buse\s+[\\\\A-Za-z_]|\bclone\s|\bfunction\s*\(#i', $expr)) {
                $findings[] = '模板指令表达式中禁止对象实例化/导入（new/use/clone/匿名函数）';
                return $findings;
            }
        }
        foreach ($exprHits as $expr) {
            if (str_contains($expr, '`') || str_contains($expr, '$_') || str_contains($expr, '${')
                || preg_match('#\$\w+\s*\(#', $expr) || preg_match($pattern, $expr) || preg_match($funcPattern, $expr) || preg_match($stmtPattern, $expr)
            ) {
                $findings[] = '模板指令表达式中包含高危写法，禁止编辑';
                return $findings;
            }
        }
        return $findings;
    }

    /**
     * 递归列目录（后台模板文件树的构建器）：子目录 => 递归结果（以目录名为键），
     * 普通文件 => 数字键的值列表项；`.`/`..` 跳过。
     * @param string $path 目录绝对路径（须调用方先做 realpath + 前缀校验，见 TmplManageService::filePage）
     * @return array 混合数组（键名=子目录，值=子树；数字键=文件名）；path 非目录返回空数组
     */
    public static function dirFile(string $path): array
    {
        $result = array();
        if (is_dir($path)) {
            $paths = scandir($path);
            foreach ($paths as $val) {
                $subPath = $path . '/' . $val;
                if ($val == '.' || $val == '..') {
                    continue;
                } elseif (is_dir($subPath)) {
                    $result[$val] = self::dirFile($subPath);
                } else {
                    $result[] = $val;
                }
            }
        }
        return $result;
    }

    /**
     * 过滤 dirFile() 结果，仅保留「目录型」顶层条目（值为数组且非空、或含子数组），
     * 即剔除文件项与空目录——后台模板文件树顶层展示用。
     * @param array $array dirFile() 的输出
     * @return array 保持原键名的子集
     */
    public static function topLevelDirs(array $array): array
    {
        $result = array();
        foreach ($array as $key => $value) {
            if (!is_array($value)) continue;
            $containsArray = false;
            foreach ($value as $subValue) {
                if (is_array($subValue)) {
                    $containsArray = true;
                    break;
                }
            }
            if ($containsArray || !empty($value)) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * 主题目录名 → 绝对基路径（realpath 归一，防符号链接/大小写漂移），统一补尾部单个 /。
     * @param string $theme 主题目录名（不做字符集校验；realpath 解析失败=目录不存在）
     * @return string 以 / 结尾的绝对路径；解析失败 message(-1, data_malformation) 终止（fail-closed）
     */
    private static function themeBase(string $theme): string
    {
        $base = realpath(self::templateRoot() . $theme);
        empty($base) and message(-1, lang('data_malformation'));
        return rtrim($base, '/') . '/';
    }

    /**
     * 插件目录名净化（防路径穿越/空字节注入）：剥首尾 /，拒空串、含 `..`、含 NUL、
     * 字符集 [a-zA-Z0-9_\-/] 之外的输入；任一违规 message(-1, data_malformation) 终止（fail-closed）。
     * @param string $dir
     * @return string 净化后的相对目录名（无首尾 /）
     */
    private static function sanitizeRelativeDir(string $dir): string
    {
        $dir = trim($dir, '/');
        ($dir === '' || str_contains($dir, '..') || str_contains($dir, "\0") || !preg_match('#^[a-zA-Z0-9_\\-/]+$#', $dir)) and message(-1, lang('data_malformation'));
        return $dir;
    }

    /**
     * 模板文件名净化：剥首部 /，拒空串、含 `..`、含 NUL、字符集 [a-zA-Z0-9_\-./] 之外（允许 `.` 以保留扩展名，
     * 但双点序列已被显式拦截）。
     * @param string $filename 相对模板文件名
     * @return string 净化后的文件名；违规 message(-1, data_malformation) 终止（fail-closed）
     */
    private static function sanitizeRelativeTemplateFile(string $filename): string
    {
        $filename = ltrim(trim($filename), '/');
        ($filename === '' || str_contains($filename, '..') || str_contains($filename, "\0") || !preg_match('#^[a-zA-Z0-9_\\-./]+$#', $filename)) and message(-1, lang('data_malformation'));
        return $filename;
    }
}
