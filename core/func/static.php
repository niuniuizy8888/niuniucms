<?php
/**
 * 静态文件服务 + 域名处理函数
 * 从 index.php 提取，简化入口文件
 */

/**
 * 获取站点根域名（可注册域 / eTLD+1）—— 基于公共后缀表（Public Suffix List）判定。
 *
 * 取代原「硬编码二级后缀白名单 + 取最后 N 段」实现。原实现有两类确定性错误：
 *   1. 白名单是死列表 ⇒ 未收录的后缀（新 gTLD 与大量 ccSLD）一律按「最后两段」截断；
 *   2. 无视 eTLD 的真实长度 ⇒ 任何子域都被截错：
 *        a.b.nncms.com   → b.nncms.com   （应为 nncms.com）
 *        shop.abc.com.cn → abc.com.cn    （应为 abc.com.cn，此处侥幸正确；但 api.abc.vip → api.abc.vip 错）
 * 该函数服务于站群域名匹配（core/config/domain.php 的 site 键）与后台「站点域名」保存，
 * 截错即站群失配 / 域名被写坏，故改为按标准 PSL 规则求「公共后缀 + 1 段」。
 *
 * 规则来源（按序回退，全程不依赖网络）：
 *   1. 编译缓存 <cache_path>tld_rules.php（首次由系统 PSL 生成，命中即 include，OPcache 友好）；
 *   2. 系统 PSL：/usr/share/publicsuffix/public_suffix_list.dat（Debian/Ubuntu publicsuffix 包）；
 *   3. 内置兜底列表（见 _domain_suffix_fallback），保证离线/最小容器下行为合理。
 *
 * @param string|null $host  主机名、URL 或 Host 头；null = 取当前请求
 * @param int         $level 保留参数（历史 API 兼容，已不参与判定）
 * @return string 根域名；IP 原样返回；单段主机（localhost）原样返回
 */
function get_domain($host = null, $level = 2)
{
    if ($host === null) {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    }
    $host = trim((string)$host);
    if ($host === '') return '';
    // 去 scheme / 去路径与查询串（定界符用 ~：# 会与字符类里的 # 冲突）
    $host = preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', $host);
    $host = preg_replace('~[/?\#].*$~', '', $host);
    // 去端口（IPv6 字面量 [::1]:8080 优先）
    if (preg_match('/^\[(.+)\](?::\d+)?$/', $host, $m)) {
        $hostNoPort = $m[1];
    } else {
        $hostNoPort = preg_replace('/:\d+$/', '', $host);
    }
    $hostNoPort = strtolower(rtrim($hostNoPort, '.'));
    if ($hostNoPort === '') return '';
    // IP（v4/v6）不做后缀切分
    if (filter_var($hostNoPort, FILTER_VALIDATE_IP)) {
        return $hostNoPort;
    }
    // 单段主机（localhost、内网短名）：无后缀可切
    if (strpos($hostNoPort, '.') === false) {
        return $hostNoPort;
    }
    // 国际化域名归一到 punycode：PSL 规则以 punycode 形式存在（xn--）
    $ascii = $hostNoPort;
    if (preg_match('/[^\x20-\x7e]/', $hostNoPort) && function_exists('idn_to_ascii')) {
        $conv = @idn_to_ascii($hostNoPort, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if (is_string($conv) && $conv !== '') $ascii = $conv;
    }
    $parts = explode('.', $ascii);
    $count = count($parts);
    $suffix = _domain_public_suffix($ascii);
    if ($suffix === null) {
        // 规则缺失（未收录后缀）：保守取最后两段（与 PSL 未命中时的通行兜底一致）
        return $count <= 2 ? $ascii : implode('.', array_slice($parts, -2));
    }
    $suffixLabels = substr_count($suffix, '.') + 1;
    if ($count <= $suffixLabels) {
        // 主机本身就是公共后缀（或更短）：无处可退，原样返回
        return $ascii;
    }
    return implode('.', array_slice($parts, -($suffixLabels + 1)));
}

/**
 * 取完整主机名（不折叠到主域名）：去 scheme/路径/端口、小写、去尾点，IDN 转 punycode。
 * 站群「精确主机优先」匹配用（安全审查第 2 轮 U-8：www.a.com 与 m.a.com 可分别配置）。
 * @param string|null $host 主机串；NULL 取当前请求 Host
 * @return string 主机名；空或非法返回 ''
 */
function get_host_name($host = null)
{
    if ($host === null) {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    }
    $host = trim((string)$host);
    if ($host === '') return '';
    $host = preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', $host);
    $host = preg_replace('~[/?\#].*$~', '', $host);
    if (preg_match('/^\[(.+)\](?::\d+)?$/', $host, $m)) {
        $host = $m[1];
    } else {
        $host = preg_replace('/:\d+$/', '', $host);
    }
    $host = strtolower(rtrim($host, '.'));
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) return $host;
    if (preg_match('/[^\x20-\x7e]/', $host) && function_exists('idn_to_ascii')) {
        $conv = @idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if (is_string($conv) && $conv !== '') $host = $conv;
    }
    return $host;
}

/**
 * 求 host 的公共后缀（PSL 匹配：精确 > 通配 > 例外，取最长匹配）。
 * 规则表为小写 punycode；'*.' 为通配规则、'!' 为例外规则。
 *
 * @param string $host 已小写、无端口的纯主机名
 * @return string|null 公共后缀；无规则命中返回 null
 */
function _domain_public_suffix(string $host)
{
    $rules = _domain_suffix_rules();
    if (empty($rules)) return null;
    $labels = explode('.', $host);
    $n = count($labels);
    if ($n < 2) return null;
    // 自最长候选起逐级回退：首个命中即最长匹配
    for ($i = 0; $i < $n; $i++) {
        $rest = array_slice($labels, $i);
        $cand = implode('.', $rest);
        // 例外规则：公共后缀 = 去掉最左标签（如 !city.kawasaki.jp → kawasaki.jp）
        if (isset($rules['!' . $cand])) {
            return count($rest) > 1 ? implode('.', array_slice($rest, 1)) : $cand;
        }
        // 精确规则
        if (isset($rules[$cand])) {
            return $cand;
        }
        // 通配规则：*.foo 命中 a.foo ⇒ 公共后缀为 a.foo
        if (count($rest) > 1 && isset($rules['*.' . implode('.', array_slice($rest, 1))])) {
            return $cand;
        }
    }
    return null;
}

/**
 * 公共后缀规则表（带缓存）。
 *
 * 编译产物为 <cache_path>tld_rules.php —— 形如 <?php return array('com'=>1,...); 的纯数组，
 * include 一次即得哈希表（OPcache 缓存，避免每请求解析 330KB 文本）。
 * 版本号变更即失效重建，便于算法升级后自动刷新。
 *
 * @return array<string,int> 规则 => 1
 */
function _domain_suffix_rules(): array
{
    static $rules = null;
    if ($rules !== null) return $rules;

    $version = 1;
    $cacheFile = '';
    $conf = function_exists('_SERVER') ? _SERVER('conf') : (isset($_SERVER['conf']) ? $_SERVER['conf'] : null);
    if (is_array($conf) && !empty($conf['cache_path'])) {
        $cacheFile = rtrim((string)$conf['cache_path'], '/\\') . '/tld_rules.php';
    }

    // 1) 编译缓存
    if ($cacheFile !== '' && is_file($cacheFile)) {
        $cached = @include $cacheFile;
        if (is_array($cached) && isset($cached['__v']) && (int)$cached['__v'] === $version && !empty($cached['rules'])) {
            // 系统 PSL 比缓存新 ⇒ 重建（PSL 更新后免手工清缓存）
            $src = _domain_psl_path();
            if ($src === '' || !is_file($src) || filemtime($src) <= filemtime($cacheFile)) {
                $rules = $cached['rules'];
                return $rules;
            }
        }
    }

    // 2) 系统 PSL → 编译
    $psl = _domain_psl_path();
    if ($psl !== '' && is_file($psl) && is_readable($psl)) {
        $parsed = _domain_suffix_parse($psl);
        if (!empty($parsed)) {
            $rules = $parsed;
            if ($cacheFile !== '') {
                _domain_suffix_cache_write($cacheFile, $version, $parsed);
            }
            return $rules;
        }
    }

    // 3) 内置兜底
    $rules = _domain_suffix_fallback();
    return $rules;
}

/**
 * 系统公共后缀表路径（找不到返回空串）。
 */
function _domain_psl_path(): string
{
    static $path = null;
    if ($path !== null) return $path;
    $candidates = array(
        '/usr/share/publicsuffix/public_suffix_list.dat',
        '/usr/share/publicsuffix/effective_tld_names.dat',
        '/usr/local/share/publicsuffix/public_suffix_list.dat',
        '/etc/publicsuffix/public_suffix_list.dat',
    );
    $path = '';
    foreach ($candidates as $f) {
        if (_domain_path_allowed($f) && is_file($f) && is_readable($f)) {
            $path = $f;
            break;
        }
    }
    return $path;
}

/**
 * open_basedir 场景下，先判断目标路径是否落在允许目录内，避免 is_file() 直接抛 warning。
 */
function _domain_path_allowed(string $file): bool
{
    $basedir = (string)ini_get('open_basedir');
    if ($basedir === '') return true;

    $target = str_replace('\\', '/', $file);
    foreach (explode(PATH_SEPARATOR, $basedir) as $dir) {
        $dir = trim((string)$dir);
        if ($dir === '') continue;
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if ($dir === '') continue;
        if ($target === $dir || strncmp($target, $dir . '/', strlen($dir) + 1) === 0) {
            return true;
        }
    }

    return false;
}

/**
 * 解析 PSL 文本，仅取 ICANN 段（不取 PRIVATE 段）。
 *
 * 为何排除 PRIVATE：本函数用于站群「本站主域」归并，需把同一站点下的子域都归到同一根域。
 * 若纳入 PRIVATE 段，foo.github.io 会被当作独立可注册域，导致同站多子域无法归并；
 * 面向本站运营场景，ICANN 段语义更正确（浏览器 cookie 隔离才需要 PRIVATE 段）。
 *
 * @return array<string,int>
 */
function _domain_suffix_parse(string $file): array
{
    $rules = array();
    $inIcann = false;
    $handle = @fopen($file, 'r');
    if (!$handle) return $rules;
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        if (substr($line, 0, 2) === '//') {
            if (strpos($line, 'BEGIN ICANN') !== false) { $inIcann = true; continue; }
            if (strpos($line, 'END ICANN') !== false) { $inIcann = false; continue; }
            if (strpos($line, 'BEGIN PRIVATE') !== false) { $inIcann = false; continue; }
            continue;
        }
        if (!$inIcann) continue;
        $rules[strtolower($line)] = 1;
    }
    fclose($handle);
    return $rules;
}

/**
 * 写编译缓存（失败静默：拿不到写权限时退回每次解析，不影响正确性）。
 */
function _domain_suffix_cache_write(string $file, int $version, array $rules): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) return;
    $body = "<?php\n// 公共后缀规则编译缓存（get_domain 用）；源: /usr/share/publicsuffix/public_suffix_list.dat\n"
        . "// 由 core/func/static.php 自动生成，可安全删除（会按需重建）。\n"
        . 'return ' . var_export(array('__v' => $version, 'rules' => $rules), true) . ";\n";
    $tmp = $file . '.' . uniqid('', true) . '.tmp';
    if (@file_put_contents($tmp, $body, LOCK_EX) === false) {
        @unlink($tmp);
        return;
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
    }
}

/**
 * 内置兜底公共后缀表：系统 PSL 缺失时使用。
 * 只收录「多标签公共后缀」（单标签 TLD 已被「最后两段」规则天然覆盖），
 * 覆盖 cn/jp/uk/tw/hk/sg/au/kr/th/id/in/vn/my/ph/br/mx/ar/za/eg/tr/il/nz/pl/ru/ua 等常见国别。
 *
 * @return array<string,int>
 */
function _domain_suffix_fallback(): array
{
    static $rules = null;
    if ($rules !== null) return $rules;
    $list = array(
        // 中国
        'com.cn','net.cn','org.cn','gov.cn','edu.cn','ac.cn','bj.cn','sh.cn','gd.cn','zj.cn','js.cn','fj.cn','sd.cn','hn.cn','hb.cn','sc.cn','yn.cn','gz.cn','gx.cn','sx.cn','xj.cn','gs.cn','nx.cn','qh.cn','xz.cn','jl.cn','ln.cn','hl.cn','he.cn','ah.cn','jx.cn','hi.cn','tj.cn','cq.cn','mo.cn','tw.cn','hk.cn',
        // 台湾 / 香港 / 澳门
        'com.tw','net.tw','org.tw','edu.tw','gov.tw','idv.tw','game.tw','ebiz.tw','club.tw','com.hk','net.hk','org.hk','edu.hk','gov.hk','idv.hk','com.mo','net.mo','org.mo','edu.mo','gov.mo',
        // 日本 / 韩国
        'co.jp','ne.jp','or.jp','go.jp','ac.jp','ad.jp','ed.jp','gr.jp','lg.jp','geo.jp','co.kr','ne.kr','or.kr','re.kr','pe.kr','go.kr','ac.kr','hs.kr','ms.kr','es.kr','sc.kr','kg.kr',
        // 英国 / 爱尔兰
        'co.uk','org.uk','gov.uk','ac.uk','me.uk','net.uk','sch.uk','ltd.uk','plc.uk','nhs.uk','police.uk','mod.uk','mil.uk','com.ie','net.ie','org.ie','gov.ie','edu.ie',
        // 东南亚 / 南亚
        'com.sg','net.sg','org.sg','edu.sg','gov.sg','per.sg','com.my','net.my','org.my','edu.my','gov.my','com.ph','net.ph','org.ph','edu.ph','gov.ph','co.th','in.th','or.th','ac.th','go.th','mi.th','net.th','co.id','or.id','ac.id','go.id','sch.id','web.id','my.id','biz.id','co.in','net.in','org.in','gen.in','firm.in','ind.in','gov.in','ac.in','edu.in','res.in','mil.in','nic.in','com.vn','net.vn','org.vn','edu.vn','gov.vn','com.bd','net.bd','org.bd','edu.bd','gov.bd','com.np','com.lk','com.pk','com.mm','com.kh','com.la','com.bn',
        // 大洋洲
        'com.au','net.au','org.au','edu.au','gov.au','asn.au','id.au','csiro.au','co.nz','net.nz','org.nz','govt.nz','ac.nz','geek.nz','gen.nz','kiwi.nz','maori.nz','school.nz',
        // 欧洲
        'com.de','net.de','org.de','co.de','com.fr','net.fr','org.fr','com.es','net.es','org.es','gob.es','edu.es','com.it','net.it','org.it','gov.it','edu.it','com.pt','net.pt','org.pt','gov.pt','edu.pt','com.nl','net.nl','org.nl','co.nl','com.be','net.be','org.be','com.pl','net.pl','org.pl','com.pl','waw.pl','gov.pl','com.ru','net.ru','org.ru','msk.ru','spb.ru','com.ua','net.ua','org.ua','gov.ua','edu.ua','com.tr','net.tr','org.tr','gov.tr','edu.tr','com.gr','net.gr','org.gr','gov.gr','edu.gr','com.se','net.se','org.se','com.no','net.no','org.no','com.dk','net.dk','org.dk','com.fi','net.fi','org.fi','com.ch','net.ch','org.ch','com.at','net.at','org.at','com.cz','net.cz','org.cz','com.hu','net.hu','org.hu','com.ro','net.ro','org.ro','com.bg','net.bg','org.bg','com.hr','net.hr','org.hr','com.rs','net.rs','org.rs','com.sk','net.sk','org.sk','com.si','net.si','org.si','com.lt','net.lt','org.lt','com.lv','net.lv','org.lv','com.ee','net.ee','org.ee','com.is','net.is','org.is','com.ie',
        // 美洲
        'com.br','net.br','org.br','gov.br','edu.br','com.mx','net.mx','org.mx','gob.mx','edu.mx','com.ar','net.ar','org.ar','gob.ar','edu.ar','com.co','net.co','org.co','gov.co','edu.co','com.pe','net.pe','org.pe','gob.pe','edu.pe','com.ve','net.ve','org.ve','com.ec','com.uy','com.py','com.bo','com.cl','gob.cl','com.ca','qc.ca','on.ca','bc.ca','ab.ca','mb.ca','ns.ca','nb.ca','nl.ca','pe.ca','sk.ca','yk.ca','nt.ca','nu.ca',
        // 非洲 / 中东
        'co.za','net.za','org.za','gov.za','ac.za','web.za','co.ke','or.ke','ne.ke','go.ke','ac.ke','co.tz','co.ug','com.ng','net.ng','org.ng','gov.ng','edu.ng','com.eg','net.eg','org.eg','gov.eg','edu.eg','com.gh','com.ma','co.ma','gov.ma','ac.ma','com.dz','com.tn','com.ly','com.sa','net.sa','org.sa','gov.sa','edu.sa','med.sa','pub.sa','com.ae','net.ae','org.ae','gov.ae','ac.ae','sch.ae','co.il','net.il','org.il','gov.il','ac.il','edu.il','muni.il','com.ir','net.ir','org.ir','gov.ir','ac.ir','com.jo','com.lb','com.kw','com.qa','com.om','com.bh',
    );
    $rules = array();
    foreach ($list as $r) {
        $rules[$r] = 1;
    }
    return $rules;
}

/**
 * 静态文件 MIME 映射
 */
function getStaticMime(): array
{
    return [
        'css' => 'text/css; charset=utf-8',
        'js'  => 'application/javascript; charset=utf-8',
        'map' => 'application/json; charset=utf-8',
        'json'=> 'application/json; charset=utf-8',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg'=> 'image/jpeg',
        'gif' => 'image/gif', 'webp'=> 'image/webp', 'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff'=> 'font/woff', 'woff2'=> 'font/woff2', 'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
        'mp4' => 'video/mp4', 'm3u8'=> 'application/vnd.apple.mpegurl',
        'ts'  => 'video/mp2t',
    ];
}

/**
 * 服务静态文件，返回 true 表示已处理
 */
function serveStaticFile(string $reqPath): bool
{
    $ext = strtolower(pathinfo($reqPath, PATHINFO_EXTENSION));
    $mime = getStaticMime();
    if (!$ext || !isset($mime[$ext])) return false;
    if (strpos($reqPath, "\0") !== false || strpos($reqPath, '..') !== false) {
        header('HTTP/1.1 400 Bad Request');
        exit;
    }
    // 安全加固：目录白名单（本通道是「全请求进 PHP」形态下 web 层 deny 的旁路，
    // 不加限制会把 runtime/、.skills/ 等目录下的白名单扩展文件（.json/.js/.css 等）按 URL 送达）
    $seg = explode('/', ltrim($reqPath, '/'));
    $first = isset($seg[0]) ? $seg[0] : '';
    if (count($seg) > 1) {
        if (!in_array($first, array('static', 'upload', 'template', 'plugins', 'addons'), TRUE) || '' === $first) {
            header('HTTP/1.1 404 Not Found');
            exit;
        }
    } else {
        if (!in_array($first, array('favicon.ico', 'robots.txt'), TRUE)) {
            header('HTTP/1.1 404 Not Found');
            exit;
        }
    }
    $file = APP_PATH . ltrim($reqPath, '/');
    if (is_file($file)) {
        header('Content-Type: ' . $mime[$ext]);
        header('Cache-Control: public, max-age=604800');
        // M4 加固：防 MIME 嗅探 + SVG 直出脚本执行
        header('X-Content-Type-Options: nosniff');
        if ('svg' === $ext) {
            // SVG 直出可执行内嵌 <script>（存储型 XSS 面）；img 引用不受 CSP 影响（img 内 SVG 本就无脚本执行）
            header("Content-Security-Policy: script-src 'none'");
        }
        readfile($file);
        exit;
    }
    header('HTTP/1.1 404 Not Found');
    exit;
}
