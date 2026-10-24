<?php
/**
 * 模板表达式/主题钩子安全闸自测（审查轮 U-1 步骤 5）。不随 build.sh 发布（tools/ 不打包）。
 * 用法：php tools/tmpl_selftest.php   （退出码 0=全过，非 0=有用例未达预期）
 * 可选：被 tools/av_precheck.sh 或 CI 调用做回归。
 *
 * 校验两件事：
 *  1) 攻击语料（赋值/$$/反引号/-> / :: / new / 闭包 / 插值 / GLOBALS / 超全局 / include /
 *     call_user_func / array_map 回调 / 变量函数 / 强制转换(array) / 命名空间限定名 等）必须被拒绝；
 *  2) 合法语料（!empty($v['remarks'])、$k==0、group_access()、function_exists、defined、
 *     xn_debug_info、DEBUG、isset、嵌套数组、三元、点号公开键路径 等）必须通过。
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$ROOT = dirname(__DIR__);
require $ROOT . '/core/app/Service/System/TemplateService.php';
require $ROOT . '/core/app/Service/System/LexicalSafetyService.php';
use App\Service\System\LexicalSafetyService as LX;

$fail = 0; $pass = 0;
function expr_reject($e, &$fail, &$pass) {
    $f = LX::scanExpression($e, true);
    if (empty($f)) { echo "  [FAIL reject] {$e}\n"; $fail++; }
    else { $pass++; }
}
function expr_allow($e, &$fail, &$pass) {
    $f = LX::scanExpression($e, true);
    if (!empty($f)) { echo "  [FAIL allow ] {$e}  => " . $f[0]['kind'] . ' ' . $f[0]['name'] . "\n"; $fail++; }
    else { $pass++; }
}
function hook_reject($c, &$fail, &$pass) {
    $f = LX::scanThemeHook($c);
    if (empty($f)) { echo "  [FAIL hook-reject] {$c}\n"; $fail++; } else { $pass++; }
}
function hook_allow($c, &$fail, &$pass) {
    $f = LX::scanThemeHook($c);
    if (!empty($f)) { echo "  [FAIL hook-allow ] {$c} => " . $f[0]['kind'] . ' ' . $f[0]['name'] . "\n"; $fail++; } else { $pass++; }
}

$C = chr(96); // backtick
$attacks = array(
    '$f="sys"."tem"',
    '$f(\'id\')',
    '$$x',
    $C . 'id' . $C,
    '$o->foo()',
    '$o->$m()',
    'Foo::bar()',
    'self::x()',
    'new X()',
    'new \\ReflectionFunction("x")',
    '(new \\PharData("/tmp/x.tar"))->addFromString("a","b")',
    'use \\ReflectionFunction',
    'function(){ return 1; }',
    'fn($x)=>$x',
    '"$x"',
    '"a{$x}b"',
    '$GLOBALS["x"]',
    '$_SERVER["HTTP_HOST"]',
    '$_GET["x"]',
    '$_SESSION["uid"]',
    'include("x")',
    'require $x',
    'eval("1")',
    'call_user_func("system","id")',
    'call_user_func_array("system",array("id"))',
    'array_map("system",$a)',
    'preg_replace_callback("/x/","system",$s)',
    'system("id")',
    'exec("id")',
    'assert("1")',
    '(array)$x',
    '(object)$x',
    '$a=1',
    '$a+=1',
    '$i++',
    '${"x"}',
    '$conf["db"]',
    '$conf.db',
    '$conf.auth_key',
    '$__tplconf.cloud_api',
    '\\App\\Service\\System\\TemplateService::download("http://x/e.zip","d")',
);
$legit = array(
    "!empty(\$v['remarks'])",
    "isset(\$cate['content']) && !empty(\$cate['content'])",
    '$player_local',
    "\$user_access=='1'",
    "(\$cid==\$s['cid'])",
    'isset($v[sonlist])',
    '$v[actor]',
    '$k==0',
    "(group_access(\$gid, 'intoadmin'))",
    'defined("DEBUG") && DEBUG > 0 && DEBUG < 3 && function_exists(\'xn_debug_info\')',
    "\$conf['user_create_on']",
    'xn_debug_info()',
    "count(\$list) > 0",
    "in_array(\$id, \$ids)",
    "\$k==0 ? 'active' : ''",
    "\$conf.url_rewrite_on",
    "\$conf.danmu.stop_ad",
    "\$v['a']['b']['c']",
    "htmlspecialchars(\$v['name'])",
    "\$n % 2 == 0",
);
$hook_bad = array(
    '<?php system("id"); ?>',
    '<?php eval($_POST["x"]); ?>',
    '<?php $f="sys"."tem"; $f("id"); ?>',
    '<?php call_user_func("system","id"); ?>',
    '<?php foreach($x as $y){ unlink($y); } ?>',
    '<?php kv_set($evil); ?>',
    '<?php echo 1; ?>',
);
$hook_good = array(
    '<?php kv_delete("theme_abc_cfg"); ?>',
    "<?php db_query('DELETE FROM nc_block WHERE name=?', 'x'); ?>",
    '<?php kv_set("k","v"); kv_delete("j"); ?>',
    '',
    '<div>pure html</div>',
);

echo "== attacks (must reject) ==\n";
foreach ($attacks as $e) expr_reject($e, $fail, $pass);
echo "== legit (must allow) ==\n";
foreach ($legit as $e) expr_allow($e, $fail, $pass);
echo "== theme hooks bad (must reject) ==\n";
foreach ($hook_bad as $c) hook_reject($c, $fail, $pass);
echo "== theme hooks good (must allow) ==\n";
foreach ($hook_good as $c) hook_allow($c, $fail, $pass);

echo "\nRESULT: pass={$pass} fail={$fail}\n";
exit($fail === 0 ? 0 : 1);
