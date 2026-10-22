<?php
// hook model_view_start.php
/**
 * 模板编译预热 - 遍历所有模板文件触发编译缓存，避免首次访问编译延迟
 * 用法: tmpl_prewarm()              # 仅编译过期/缺失的
 *       tmpl_prewarm(true)          # 强制全部重新编译
 *       tmpl_prewarm(false, true)   # 静默模式，返回数组
 */
function tmpl_prewarm(bool $force = false, bool $silent = false): array
{
    // hook model_view_tmpl_prewarm_start.php

    $result = ['total' => 0, 'compiled' => 0, 'errors' => []];
    $files = glob_recursive(\App\Service\System\TemplateService::themeHtmlGlob());
    if (empty($files)) return $result;
    foreach ($files as $srcFile) {
        $result['total']++;
        try {
            if ($force) {
                $compiled = _SERVER('conf')['cache_path'] . substr(str_replace('/', '_', $srcFile), strlen(APP_PATH));
                if (is_file($compiled)) @unlink($compiled);
            }
            _file($srcFile);
            $result['compiled']++;
            !$silent and print("  [OK] " . str_replace(APP_PATH, '', $srcFile) . "\n");
        } catch (\Throwable $e) {
            $result['errors'][] = basename($srcFile) . ': ' . $e->getMessage();
            !$silent and print("  [ERR] " . str_replace(APP_PATH, '', $srcFile) . " - " . $e->getMessage() . "\n");
        }
    }
    if (!$silent) {
        printf("\n\u6a21\u677f\u7f16\u8bd1\u9884\u70ed\u5b8c\u6210: %d/%d \u6210\u529f\n", $result['compiled'], $result['total']);
        if ($result['errors']) printf("%d \u5931\u8d25\n", count($result['errors']));
    }
    // hook model_view_tmpl_prewarm_end.php
    return $result;
}

/**
 * 模板编译器核心：把模板源编译成缓存产物并返回产物路径，供 `include _file(...)` 使用。
 *
 * 失效判定：产物缺失 / DEBUG>1 / 源比产物新 / hook 签名变化，任一命中即重编。
 * 重编流水（顺序敏感）：读 overwrite 替换后的源 → BOM 剥离 → 先剥模板原生 PHP
 * （tpl_allow_php 安全规则，只作用于模板作者内容）→ 内联插件 hook 标记 →
 * 依次展开 {inc:}/{php}/{block:}/{loop:}/{if:}/点语法变量/{@EXPR}/{$var}/<template include>
 * → 写盘前产物语法闸（解析失败拒写，有旧产物沿用、无旧产物降级合法空产物，签名不推进=每请求重试）。
 * 末尾按 conf['compress']（0/1/2）对 html/php 产物做去空白/去注释压缩。
 *
 * 安全要点（审计 S-1/High）：编译产物里 `{$var}` 是**裸 echo**（见 process_vars* 返回值），
 * 输出层零转义——整条 XSS 防线押在「入库前已转义」纪律上，新增入库字段必须显式转义。
 *
 * @param string $srcfile 模板源绝对路径（view_load()/tmpl_prewarm() 产出）
 * @return string 编译缓存产物的绝对路径（$conf['cache_path'] 下，路径分隔符转下划线）
 */
function _file($srcfile)
{
    // hook model_view__file_start.php

    global $conf; 
    $tmpfile = $conf['cache_path'] . substr(str_replace('/', '_', $srcfile), strlen(APP_PATH));
    $srcM = is_file($srcfile) ? filemtime($srcfile) : 0;
    $tmpM = is_file($tmpfile) ? filemtime($tmpfile) : 0;
    if (!is_file($tmpfile) || DEBUG > 1 || ($srcM && $tmpM && $srcM > $tmpM) || \App\Service\System\AddonService::hookSigStaleFor($tmpfile)) {
        // 顺序修正：先剥离模板源的原生 php（tpl_allow_php=0 安全规则，只作用于模板作者内容），
        // 再内联插件 hook（受信代码，其 php 逻辑段不参与剥离——此前内联在前、剥离在后，wrapped 格式
        // hook 的 kv 读取/变量赋值整段被清空，只剩 HTML 壳无条件输出=配置开关失效）。
        // 并补 hook 签名失效门（hook 文件改动 → 模板产物重建，对齐 includeFile 管线语义）。
        $s = file_get_contents(\App\Service\System\AddonService::findOverwrite($srcfile));
        if ($s !== '' && substr($s, 0, 3) === "\xEF\xBB\xBF") $s = substr($s, 3);
        $s = ltrim($s);
        $s = preg_replace('#(?:\<\?.*?\?\>|\<\?.*)#s', '', $s);
        $s = \App\Service\System\AddonService::inlineHookMarkers($s);
        $reg_arr = '[a-zA-Z_]\w*(?:\[\w+\]|\[\'\w+\'\]|\[\"\w+\"\]|\[\$[a-zA-Z_]\w*\])*';
        //2025.09.07 修复inc
        $s = preg_replace_callback(
            '#\{inc\:([\w|\/\.]+)\}#',
            function ($matches) use ($srcfile) {
                return process_inc($matches, dirname($srcfile));
            },
            $s
        );
        /*0ld----$s = preg_replace_callback('#\{inc\:([\w|\/\.]+)\}#', 'process_inc', $s);*/
        // 移除原「内联后剥离」行（原 line 58）：剥离已前置到 hook 内联之前——
        // 原位置会把刚内联进来的插件 hook php 逻辑再次剥空（wrapped 格式 hook 开关/配置失效=假工作）。
        $allow_tpl_php = !empty($conf['tpl_allow_php']);
        if ($allow_tpl_php) {
            $s = preg_replace('#\{php\}(.*?)\{\/php\}#s', '<?php \\1 ?>', $s);
        } else {
            // 项目审查补齐：剥除属**安全事件**，必须与 KV 配置层同口径留痕
            // （config_runtime_audit_forbidden 写 config_audit + admin_audit_log('config_forbidden_key_blocked')）。
            // 此前模板面静默剥除、零审计 ⇒ 主题/插件/模板里塞 {php} 不留任何线索，排障口径形同虚设。
            // 判据与剥除正则同形（`{php}…{/php}` 成对），避免只出现半截 `{php}` 也写审计。
            $phpStripped = 0;
            $s = preg_replace('#\{php\}.*?\{\/php\}#s', '', $s, -1, $phpStripped);
            if ($phpStripped > 0) {
                xn_log('template tag stripped: {php} x' . $phpStripped . ' in ' . $srcfile, 'config_audit');
                function_exists('admin_audit_log') and admin_audit_log('config_forbidden_key_blocked', array('key' => 'tpl_tag_php', 'file' => $srcfile, 'count' => $phpStripped));
            }
        }
        $s = preg_replace_callback('#\{block\:([a-zA-Z_]\w*)\040?([^\n\}]*?)\}(.*?){\/block}#s', 'process_block', $s);
        while (preg_match('#\{loop\:\$' . $reg_arr . '(?:\040\$[a-zA-Z_]\w*){1,2}\}.*?\{\/loop\}#s', $s))
            $s = preg_replace_callback('#\{loop\:(\$' . $reg_arr . '(?:\040\$[a-zA-Z_]\w*){1,2})\}(.*?)\{\/loop\}#s', 'process_loop', $s);
        while (preg_match('#\{if\:[^\n\}]+\}.*?\{\/if\}#s', $s))
            $s = preg_replace_callback('#\{if\:([^\n\}]+)\}(.*?)\{\/if\}#s', 'process_if', $s); 
        // {h:$a.b} / {h:$a}：HTML 转义输出（htmlspecialchars ENT_QUOTES，不二次编码已有实体）
        $s = preg_replace_callback('#\{h\:\$([a-zA-Z_]\w*(?:\.[a-zA-Z0-9_]+)*)\}#', 'process_vars_h', $s);
        $s = preg_replace_callback('#\{\$([a-zA-Z_]\w*(?:\.[a-zA-Z0-9_]+)+)\}#', 'process_vars_dot', $s);
        if (!empty($conf['tpl_allow_expr'])) {
            $s = preg_replace('#\{\@([^\}]+)\}#', '<?php echo(\\1); ?>', $s);
        } else {
            // 同 {php}：表达式标签剥除也是安全事件，留痕口径一致（config_audit + 后台审计）。
            // ⚠ 判据必须与剥除正则同形（`{@` + 非 `}` 序列 + `}`）：模板里大量存在 CSS 的
            //   `{@media screen{…}` 这类**没有紧跟闭合 `}` 的** `{@` 片段，用宽判据
            //   `str_contains($s,'{@')` 会让每个含公共 `public/foot.html`（其内有一个历史
            //   `{@xn_debug_info()}`）的页面都写一条审计，把安全通道刷成噪声、真事件被淹没。
            $exprStripped = 0;
            $s = preg_replace('#\{\@[^\}]+\}#', '', $s, -1, $exprStripped);
            if ($exprStripped > 0) {
                xn_log('template tag stripped: {@EXPR} x' . $exprStripped . ' in ' . $srcfile, 'config_audit');
                function_exists('admin_audit_log') and admin_audit_log('config_forbidden_key_blocked', array('key' => 'tpl_tag_expr', 'file' => $srcfile, 'count' => $exprStripped));
            }
        }
        $s = preg_replace_callback('#\{(\$' . $reg_arr . ')\}#', 'process_vars', $s); 
        for ($i = 0; $i < 10; ++$i) {
            $s = preg_replace_callback('#<template\sinclude="(.*?)">(.*?)</template>#is', '_include_callback_1', $s);
            if (!str_contains($s, '<template')) break;
        }
        // ㉑（审核轮）：模板产物写盘前最终语法闸——解析失败拒写（有旧产物沿用，签名不推进=每请求重试）；
        // 无旧产物降级为**合法空产物**（include=no-op 页面缺块但站活——写坏内容=include 即 parse error 白屏），
        // 降级路径不推进签名 → 每请求重试直至源/hook 修复后自动恢复。
        $tplParseErr = \App\Service\System\AddonService::artifactParseError($s);
        $fgTplRejected = (NULL !== $tplParseErr);
        if ($fgTplRejected) {
            xn_log('template artifact rejected ' . $tmpfile . ' :: ' . $tplParseErr, 'addon_compile_error');
            if (is_file($tmpfile) && (int)filesize($tmpfile) > 0) {
                return $tmpfile;
            }
            $s = "<?php "; // 合法空产物降级（后续模板编译对空串 no-op）
        }
        if (!$fgTplRejected) {
        \App\Service\System\AddonService::hookSigSave($tmpfile);
        }
        $ext = file_ext($tmpfile);
        $is_html = in_array($ext, array('htm', 'html'));
        if ('php' == $ext && 0 == DEBUG && $conf['compress'] > 0) {
            file_put_contents_try($tmpfile, $s);
            $s = trim(php_strip_whitespace($tmpfile));
            file_put_contents_try($tmpfile, $s);
        } elseif ($is_html && 0 == DEBUG && $conf['compress'] > 0) {
            file_put_contents_try($tmpfile, $s);
            $s = addons_compile_srcfile($tmpfile);
            if (1 == $conf['compress']) {
                $s = str_replace(array("\t"), '', $s);
                $s = preg_replace(array("#> *([^ ]*) *<#", "#<!--[\\w\\W\r\\n]*?-->#", "# \"#", '#>\s+<#', "#/\*[^*]*\*/#", "//", '#\/\*(\s|.)*?\*\/#', "#>\s+\r\n#"), array(">\\1<", '', "\"", '><', '', '', '', '>'), $s);
            } elseif (2 == $conf['compress']) {
                $s = preg_replace(array("#> *([^ ]*) *<#", "#[\s]+#", "#<!--[\\w\\W\r\\n]*?-->#", "# \"#", "#/\*[^*]*\*/#", "//", '#>\s+<#', '#\/\*(\s|.)*?\*\/#'), array(">\\1<", ' ', '', "\"", '', '', '><', ''), $s);
            }
            file_put_contents_try($tmpfile, $s);
        } else {
            file_put_contents_try($tmpfile, $s);
        }
    }
    // hook model_view__file_end.php
    return $tmpfile;
}
/**
 * 点语法变量转数组下标：`a.b.c` → `$a['b']['c']`（供模板 `{$a.b}` 编译）。
 *
 * @param string $str 不含前导 $ 的点分变量路径（process_vars_dot 的捕获组 1）
 * @return string 以 $ 开头的 PHP 数组访问表达式
 */
function dot_to_array_syntax($str)
{
    // hook model_view_dot_to_array_syntax_start.php

    $parts = explode('.', $str);
    $base = array_shift($parts);
    // hook model_view_dot_to_array_syntax_end.php
    return '$' . $base . implode('', array_map(function($v) { return "['$v']"; }, $parts));
}
/**
 * 编译 `{$a.b.c}` 点语法变量为裸 echo PHP 段（未定义时输出空串）。
 * _file() 的 preg_replace_callback 回调，入参为其捕获组（当前正则仅 1 组，$matches[2] 恒缺省）。
 *
 * @param array $matches preg 捕获数组：[1]=点分变量路径
 * @return string `<?php echo isset(...) ? ... : ''; ?>` 片段（输出层不转义，见 _file 注释）
 */
function process_vars_dot($matches)
{
    // hook model_view_process_vars_dot_start.php

    $var = dot_to_array_syntax($matches[1]);
    $default = isset($matches[2]) ? $matches[2] : "''";
    // hook model_view_process_vars_dot_end.php
    return "<?php echo isset($var) ? $var : $default; ?>";
}
/**
 * 编译 `{h:$a.b}` / `{h:$a}` 为转义 echo（htmlspecialchars ENT_QUOTES UTF-8，double_encode=false）。
 * 用于采集来源字段（名称/备注/简介等）在 HTML 文本与属性位的输出。
 *
 * @param array $matches preg 捕获数组：[1]=变量路径（不含 $）
 * @return string PHP 片段
 */
function process_vars_h($matches)
{
    $var = false === strpos($matches[1], '.') ? '$' . $matches[1] : dot_to_array_syntax($matches[1]);
    return "<?php echo isset($var) ? htmlspecialchars((string)$var, ENT_QUOTES, 'UTF-8', false) : ''; ?>";
}
/**
 * 编译 `{$var}` / `{$arr[key]}` 型变量为裸 echo PHP 段（未定义输出空串）。
 * 与 process_vars_dot 同为 _file() 的回调；变量表达式先经 rep_double + rep_vars 规范化引号与下标。
 * 输出层不转义（S-1 裸 echo 位置），转义纪律见 _file 注释。
 *
 * @param array $matches preg 捕获数组：[1]=含 $ 前缀的变量表达式
 * @return string `<?php echo isset($vars) ? $vars : ''; ?>` 片段
 */
function process_vars($matches)
{
    // hook model_view_process_vars_start.php

    $vars = rep_double($matches[1]);
    $vars = rep_vars($vars);
    // hook model_view_process_vars_end.php
    return "<?php echo isset($vars) ? $vars : ''; ?>";
}
/**
 * 反斜杠转义双引号还原：`\"` → `"`（模板表达式里给引号加的反斜杠在此去掉）。
 * 供 process_vars/process_if/process_loop/process_block 统一预处理捕获内容。
 *
 * @param string $s 待处理片段
 * @return string 替换后的字符串
 */
function rep_double($s)
{
    // hook model_view_rep_double_start.php

    // hook model_view_rep_double_end.php
    return str_replace('\"', '"', $s);
}
/**
 * 变量下标规范化：把 `[key]` 统一成合法 PHP 数组下标。
 *  · `[word]` / `["word"]` → `['word']`（裸词与双引号键转单引号字符串键）；
 *  · `['123']` → `[123]`（纯数字的引号键还原为整数下标）。
 *
 * @param string $s 含方括号下标的变量表达式
 * @return string 规范化后的表达式
 */
function rep_vars($s)
{
    // hook model_view_rep_vars_start.php

    $s = preg_replace('#\[(\w+)\]#', "['\\1']", $s);
    $s = preg_replace('#\[\"(\w+)\"\]#', "['\\1']", $s);
    $s = preg_replace('#\[\'(\d+)\'\]#', '[\\1]', $s);
    // hook model_view_rep_vars_end.php
    return $s;
}
/**
 * 编译 `{if:EXPR}...{elseif:EXPR}...{else}...{/if}` 块为 PHP if 结构（支持嵌套，_file() 里 while 循环反复调用直至无残留）。
 * 条件与块体均先经 rep_double/rep_vars 规范化；{elseif:} 子块交 rep_elseif 回调展开。
 * 注意：EXPR 原样进入 `if (...)`——模板作者可控表达式，受 tpl_allow_php/tpl_allow_expr 同款纪律约束，勿投喂不可信模板。
 *
 * @param array $matches preg 捕获数组：[1]=条件表达式，[2]=if 块体
 * @return string `<?php if (...) { ?>...<?php } ?>` 片段
 */
function process_if($matches)
{
    // hook model_view_process_if_start.php

    $expr = rep_double($matches[1]);
    $expr = rep_vars($expr);
    $s = preg_replace_callback('#\{elseif\:([^\n\}]+)\}#', 'rep_elseif', rep_double($matches[2]));
    $s = str_replace('{else}', '<?php }else{ ?>', $s);
    // hook model_view_process_if_end.php
    return "<?php if ($expr) { ?>$s<?php } ?>";
}
/**
 * 编译 `{elseif:EXPR}` 为 PHP `}elseif(...){` 片段（在 process_if 块体内被 preg_replace_callback 调用）。
 *
 * @param array $matches preg 捕获数组：[1]=条件表达式
 * @return string `<?php }elseif($expr) { ?>` 片段
 */
function rep_elseif($matches)
{
    // hook model_view_rep_elseif_start.php

    $expr = rep_double($matches[1]);
    $expr = rep_vars($expr);
    // hook model_view_rep_elseif_end.php
    return "<?php }elseif($expr) { ?>";
}
/**
 * 编译 `{loop:$arr $v $k}...{/loop}` 为 PHP foreach 结构。
 * 捕获组 1 按空格拆成「数组表达式 [+ 值变量名 + 键变量名]」：值默认 $v、键默认空；
 * 生成的循环先 is_array 守卫再 foreach，避免非数组触发 warning。
 *
 * @param array $matches preg 捕获数组：[1]="arr [valueVar] [keyVar]" 声明串，[2]=循环体
 * @return string `<?php if(isset($arr) && is_array($arr)){ foreach(...){ ?>...<?php }} ?>` 片段
 */
function process_loop($matches)
{
    // hook model_view_process_loop_start.php

    $args = explode(' ', rep_double($matches[1]));
    $s = rep_double($matches[2]);
    $arr = rep_vars($args[0]);
    $v = empty($args[1]) ? '$v' : $args[1];
    $k = empty($args[2]) ? '' : $args[2] . '=>';
    // hook model_view_process_loop_end.php
    return "<?php if(isset($arr) && is_array($arr)) { foreach($arr as $k&$v) { ?>$s<?php }} ?>";
}
/**
 * 编译 `{inc:文件名}` ——把子模板内容内联进主模板源。
 * 查找顺序：优先当前模板同目录（$basedir 上下文）→ 主题 html 根 → 默认主题 html 根；均未命中则返回空串。
 * 文件名拒 NUL/`..`（路径穿越防线）；被引入内容统一交 process_inc_body() 收口：
 * 重剥原生 PHP + 内容闸（VULN-C02 RCE 链的封堵点，命中即整段丢弃并留痕）。
 *
 * @param array  $matches preg 捕获数组：[1]={inc:} 目标相对路径
 * @param string $basedir 当前模板所在目录（_file() 用 dirname($srcfile) 传入；空则跳过同目录查找）
 * @return string 内联后的子模板内容（合法纯 HTML 段）或空串
 */
function process_inc($matches, $basedir = '')
{
    // hook model_view_process_inc_start.php

    global $config;
    $filename = $matches[1];
    if (str_contains($filename, '/')) {
        $arr = explode('/', $filename, 2);
        $filename = $arr[0] . '/' . $arr[1];
    }
    $filename = ltrim($filename, '/');
    if ($filename === '' || str_contains($filename, "\0") || str_contains($filename, '..')) return '';
    // 如果有上下文，就优先从当前模板所在目录找
    if ($basedir) {
        $tpl_file = $basedir . '/' . $filename;
        if (file_exists($tpl_file)) {
            // 修复：{inc:} 子模板内的 hook 标记也要内联（head/foot 子模板的 hook 点此前是死点）
            return process_inc_body(file_get_contents($tpl_file));
        }
    }
    // 否则，走默认
    $tpl_file = \App\Service\System\TemplateService::themeHtmlBase($config['theme'] ?? '') . $filename;
    if (!file_exists($tpl_file)) {
        $tpl_file = \App\Service\System\TemplateService::defaultHtmlFile($filename);
    }

    // hook model_view_process_inc_end.php
    // 修复：默认主题路径分支同样内联子模板 hook 标记
    return file_exists($tpl_file) ? process_inc_body(file_get_contents($tpl_file)) : '';
}

/**
 * {inc:} 被内联内容的统一收口（上线前审计修复，VULN-C02）
 *
 * 根因：主模板在 _file() 里先执行 `preg_replace('#(?:\<\?.*?\?\>|\<\?.*)#s','',$s)` 剥离原生 PHP，
 * **之后**才由 process_inc() 把 {inc:} 指向的文件原样拼入，且**不再重剥** ⇒ 被引入文件里的
 * `<?php ?>` 直达编译产物并执行。叠加主题 zip 内容闸只扫 `.html/.htm` 条目、`$dangerExt` 也不含
 * .txt，构成完整 RCE 链：zip 内 index.html=`{inc:x.txt}`（过闸）+ x.txt=`<?php system("id"); ?>`
 * （永不扫描）⇒ 产物即 webshell。
 *
 * 处置（两道，缺一不可——只做其中一道仍可绕）：①与主模板同口径重剥原生 PHP；
 * ②重跑与模板编辑器同口径的内容闸（legacyRegexFindings + LexicalSafetyService::scanTemplate），
 * 命中即整段丢弃并留痕。闸函数不可用时 fail-closed（丢弃内容而不是放行）。
 * 注意：<template include> 走 AddonService::includeTemplateCallback，同一位置同样位于剥 PHP 之后，
 * 已在该回调内做等价收口。
 */
function process_inc_body($s)
{
    if (!is_string($s) || $s === '') return '';
    // ① 与 _file() 同口径剥离原生 PHP（{inc:} 引入的子模板不接受编剧作者 PHP）
    $s = preg_replace('#(?:\<\?.*?\?\>|\<\?.*)#s', '', $s);
    // ② 内容闸：与模板编辑器保存路径同口径，命中即拒绝整段内联
    try {
        if (class_exists('\App\Service\System\TemplateService')
            && class_exists('\App\Service\System\LexicalSafetyService')) {
            // 规则数据缺失：fail-closed 丢弃内容，并输出可见提示（而非静默空白）
            if (!\App\Service\System\TemplateService::guardAvailable()) {
                return \App\Service\System\TemplateService::guardMissingHtml();
            }
            $findings = \App\Service\System\TemplateService::legacyRegexFindings($s);
            if (empty($findings)) {
                $findings = \App\Service\System\LexicalSafetyService::scanTemplate($s);
            }
            if (!empty($findings)) {
                if (function_exists('xn_log')) {
                    $first = $findings[0];
                    $reason = is_array($first) ? ((string)($first['kind'] ?? '') . ' ' . (string)($first['name'] ?? '')) : (string)$first;
                    xn_log('template {inc:} content gate rejected: ' . $reason, 'addon_compile_error');
                }
                return '';
            }
        } else {
            // 闸不可用（引导早期/CLI 精简上下文）：fail-closed——已剥离 PHP 的纯 HTML 才放行
            if (function_exists('xn_log')) xn_log('template {inc:} content gate unavailable, fail-closed', 'addon_compile_error');
            return '';
        }
    } catch (\Throwable $e) {
        if (function_exists('xn_log')) xn_log('template {inc:} content gate error: ' . $e->getMessage(), 'addon_compile_error');
        return '';
    }
    // 修复：{inc:} 子模板内的 hook 标记也要内联（head/foot 子模板的 hook 点此前是死点）
    return \App\Service\System\AddonService::inlineHookMarkers($s);
}
/*function process_inc($matches)
{ 
    global $config;
    if (strpos($matches[1], '/') != false) { 
        $arr = explode('/', $matches[1]);
        $filename = $arr[0] . '/' . $arr[1];
    } else {
        $filename = $matches[1];
    }
    $tpl_file = APP_PATH . 'template/' . $config['theme'] . '/html/' . $filename;
    if (!file_exists($tpl_file)) $tpl_file = APP_PATH . 'template/default/html/' . $filename; 
    return file_get_contents($tpl_file);
}*/
function process_block($matches)
{
    // hook model_view_process_block_start.php

    $func = $matches[1];
    $config = $matches[2];
    $s = $matches[3];
    $s = rep_double($s);
    $config = rep_double($config);
    $config_arr = array();
    preg_match_all('#([a-zA-Z_]\w*)="(.*?)" #', $config . ' ', $m);
    foreach ($m[2] as $k => $v) {
        if (isset($v)) $config_arr[strtolower($m[1][$k])] = $v;
    }
    unset($m);
    $func_str = 'block_' . $func . '(' . var_export($config_arr, 1) . ');';
    $before = $after = ''; 
    $before .= '<?php $data = ' . $func_str . ' ?>';
    $after .= '<?php unset($data); ?>'; 
    // hook model_view_process_block_end.php
    return $before . $s . $after;
}
/**
 * @deprecated 兼容壳：模板路径解析（原注释口径），转发 TemplateService::resolveFrontViewFile()。
 * 本壳只负责把「环境三要素」凑齐后交给服务层：
 *  - 主题名：global $config['theme'] 优先（非数组 config 会被归一成空数组），缺省走
 *    TemplateService::currentTheme()（后台设置的当前主题）；
 *  - 模板模式：$config['setting']['tpl_mode']（0/1/2，决定设备前缀形态）；
 *  - **设备探测：get_device()** ⇒ 同一 URL 在手机/微信/pad/PC 上会解析到不同模板文件
 *    （0=pc、1=wechat、2=pad、3=mobile）。
 * 服务层的解析链：主题定制（`<id>_<设备前缀名>`，仅主题 conf.json 的 installed 非空才启用）
 * → 主题通用（`<设备前缀名>`）→ 兜底回退（$dir 非空取**插件视图**，否则取 default 主题），
 * 回退档用不带设备前缀的默认文件名。
 * ⚠ 末级回退档**不校验 is_file** ⇒ 返回的路径可能指向不存在的文件，白屏/报错发生在后续 include 处；
 *   排查模板问题先看解析出的路径与主题目录是否对得上，而不是看本函数是否「成功」。
 * @param string $type 视图类型（经服务层 frontViewDefaultFile 映射成默认文件名；未知类型全链为空串）
 * @param int|string $id 分类/文章定制模板 id（0 = 不启用 id 前缀；壳内 intval）
 * @param string $dir 插件目录名（插件视图兜底用；空串 = 回退 default 主题）
 * @return string 模板文件路径（末级回退档不保证文件存在）
 */
function view_load($type = '', $id = 0, $dir = '')
{
    // hook model_view_view_load_start.php

    global $config;
    if (!is_array($config)) $config = [];
    $theme = !empty($config['theme']) ? (string)$config['theme'] : \App\Service\System\TemplateService::currentTheme();
    $tpl_mode = $config['setting']['tpl_mode'] ?? 0;
     isset($tpl_mode) || $tpl_mode = 0;
    $detect = get_device();
    // 导航高亮（服务端口径）：站点 URL 为 query 形态（/?cates-15.html），pathname 恒为 '/'，
    // 前端按 pathname 比对无法区分栏目 ⇒ 在此按 $id 沿 catelist.cup 上溯到顶级 cid。
    nn_nav_state((string)$type, intval($id));
    // hook model_view_view_load_end.php
    return \App\Service\System\TemplateService::resolveFrontViewFile((string)$type, intval($id), (string)$dir, $theme, intval($tpl_mode), intval($detect));
}



/**
 * 计算导航高亮状态并写入全局：$nav_active（顶级栏目 cid，0=无）、$nav_route（视图类型）、
 * $nav_home（首页=1）、$nav_pku（片库类列表页=1）。模板只做简单等值比较（{if:} 不得含函数调用）。
 * @param string $type 视图类型（index/list/category/type/video/player/tag/search/art/page...）
 * @param int $id 当前栏目 cid（0=无）
 * @return void
 */
function nn_nav_state($type, $id)
{
    $catelist = isset($GLOBALS['catelist']) && is_array($GLOBALS['catelist']) ? $GLOBALS['catelist'] : array();
    $cid = intval($id);
    $guard = 0;
    while ($cid && isset($catelist[$cid]) && intval($catelist[$cid]['cup'] ?? 0) > 0 && $guard++ < 10) {
        $cid = intval($catelist[$cid]['cup']);
    }
    if ($cid && !isset($catelist[$cid])) $cid = 0;
    $GLOBALS['nav_active'] = $cid;
    $GLOBALS['nav_route'] = $type;
    $GLOBALS['nav_home'] = $type === 'index' ? 1 : 0;
    $GLOBALS['nav_pku'] = in_array($type, array('list', 'category', 'type', 'cate', 'lists'), TRUE) ? 1 : 0;
}
