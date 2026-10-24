<?php
// 审查轮 U-1 步骤 4：模板兼容性扫描。
//   默认（save-gate 口径）：对 .html 原文跑 scanTemplate（含原生 PHP 标签检出）。
//   --compile（编译期口径）：先按 view.php 的 tpl_allow_php=0 剥离原生 PHP 标签段，再跑 scanTemplate，
//     反映主模板正文内容闸（view_tpl_body_gate）对真实指令的判定。
// 合法模板在 --compile 口径下应产生 0 条 finding。不发布。
error_reporting(E_ALL & ~E_DEPRECATED);
$ROOT = dirname(__DIR__);
require $ROOT . '/core/app/Service/System/TemplateService.php';
require $ROOT . '/core/app/Service/System/LexicalSafetyService.php';
use App\Service\System\LexicalSafetyService as LX;
$args = array_slice($argv, 1);
$compile = false;
$dirs = array();
foreach ($args as $a) { if ($a === '--compile') $compile = true; else $dirs[] = $a; }
if (!$dirs) { fwrite(STDERR, "usage: php tmpl_compat_scan.php [--compile] DIR...\n"); exit(2); }
$files = 0; $hitFiles = 0; $findings = 0; $byKind = array(); $sampleShown = 0;
foreach ($dirs as $d) {
    if (!is_dir($d)) { fwrite(STDERR, "skip (not dir): $d\n"); continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        if (strtolower($f->getExtension()) !== 'html') continue;
        $files++;
        $code = file_get_contents($f->getPathname());
        if ($compile) $code = preg_replace('#(?:\<\?.*?\?\>|\<\?.*)#s', '', $code);
        $res = LX::scanTemplate($code);
        if (!empty($res)) {
            $hitFiles++;
            foreach ($res as $r) {
                $findings++;
                $k = $r['kind'];
                $byKind[$k] = ($byKind[$k] ?? 0) + 1;
                if ($sampleShown < 60) {
                    $ex = isset($r['expr']) ? trim(preg_replace('/\s+/', ' ', $r['expr'])) : '';
                    if (strlen($ex) > 100) $ex = substr($ex, 0, 100) . '...';
                    echo $f->getPathname() . "\t" . $k . "\t" . $r['name'] . "\t" . $ex . "\n";
                    $sampleShown++;
                }
            }
        }
    }
}
echo "\n--- SUMMARY (" . ($compile ? 'compile' : 'save-gate') . ") ---\n";
echo "files_scanned=$files  files_with_findings=$hitFiles  total_findings=$findings\n";
foreach ($byKind as $k => $c) echo "  $k: $c\n";
exit($findings === 0 ? 0 : 1);
