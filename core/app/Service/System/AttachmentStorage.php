<?php
namespace App\Service\System;

class AttachmentStorage
{
    const KEY = 'attachment_storage';
    const MAX_BYTES = 8388608;

    public static function settings(bool $private = false): array
    {
        $c = array_merge(['backend'=>'local', 'local_url'=>'', 'local_aliases'=>[], 'host'=>'', 'port'=>21, 'user'=>'', 'password'=>'', 'directory'=>'/', 'public_url'=>'', 'passive'=>1, 'tls'=>0, 'keep_local'=>1, 'timeout'=>30], (array)kv_get(self::KEY));
        if ($private) {
            if ($c['password'] !== '') {
                $value = xn_decrypt($c['password']);
                if ($value === false || $value === '') throw new \RuntimeException('FTP 密码无法解密，请重新保存');
                $c['password'] = $value;
            }
        } else {
            $c['has_password'] = $c['password'] !== '';
            unset($c['password']);
        }
        return $c;
    }

    public static function normalize(array $input): array
    {
        $c = self::settings(true);
        foreach (['backend','local_url','host','user','directory','public_url'] as $k) if (isset($input[$k]) && is_scalar($input[$k])) $c[$k] = trim((string)$input[$k]);
        if (isset($input['password']) && is_string($input['password']) && $input['password'] !== '') $c['password'] = $input['password'];
        foreach (['passive','tls','keep_local'] as $k) $c[$k] = !empty($input[$k]) ? 1 : 0;
        $c['port'] = max(1, min(65535, (int)($input['port'] ?? 21)));
        $c['timeout'] = max(5, min(60, (int)($input['timeout'] ?? 30)));
        if (!in_array($c['backend'], ['local','ftp'], true)) throw new \InvalidArgumentException('请选择本地或 FTP 存储');
        foreach (['local_url','public_url'] as $k) {
            $c[$k] = rtrim($c[$k], '/');
            if (strlen($c[$k]) > 400) throw new \InvalidArgumentException('图片访问地址过长');
            if ($c[$k] !== '' && !self::validPublicBase($c[$k])) throw new \InvalidArgumentException('图片访问地址应为完整 HTTP(S) 地址，不带参数或 HTML 特殊字符');
        }
        if ($c['host'] !== '' && (!preg_match('/^[a-z0-9.-]+$/iD', $c['host']) || strlen($c['host']) > 253)) throw new \InvalidArgumentException('FTP 主机请填写域名或 IPv4 地址，不带协议和目录');
        if (preg_match('~[\x00-\x1f\\\\]|(?:^|/)\.\.(?:/|$)~', $c['directory'])) throw new \InvalidArgumentException('FTP 上传目录无效');
        if (strlen($c['directory']) > 300 || strlen($c['user']) > 255 || preg_match('/[\r\n\x00]/', $c['user'] . $c['password'])) throw new \InvalidArgumentException('FTP 参数无效');
        if ($c['backend'] === 'ftp') self::requireFtp($c);
        return $c;
    }

    public static function save(array $input): void
    {
        $previous = self::settings();
        $c = self::normalize($input);
        if (xn_key() === '') throw new \RuntimeException('站点密钥未配置');
        $c['password'] = $c['password'] !== '' ? xn_encrypt($c['password']) : '';
        if ($previous['local_url'] !== '' && $previous['local_url'] !== $c['local_url']) $c['local_aliases'] = array_values(array_unique(array_merge($c['local_aliases'],[$previous['local_url']])));
        if (kv_set(self::KEY, $c) === false) throw new \RuntimeException('存储设置保存失败');
        // Content caches contain formatted image URLs, so invalidate them on domain changes too.
        if ($previous['local_url'] !== $c['local_url']) cache_truncate();
        page_cache_bump(true);
    }

    private static function requireFtp(array $c): void
    {
        if ($c['host'] === '' || $c['user'] === '' || $c['password'] === '' || $c['public_url'] === '') throw new \InvalidArgumentException('请填写 FTP 主机、用户名、密码及图片访问地址');
        if (!function_exists('curl_init')) throw new \RuntimeException('服务器需要启用 PHP cURL');
    }

    public static function localUrl(string $path): string
    {
        $c = self::settings();
        $base = $c['local_url'] ?: (string)(_SERVER('conf')['upload_url'] ?? '/upload/');
        // Old settings must be safe too, including when a custom theme renders a raw URL.
        $relative = preg_match('~^/(?:[a-zA-Z0-9_-][a-zA-Z0-9_.%-]*/?)*$~D', $base) && !str_contains($base, '..');
        if (!self::validPublicBase($base) && !$relative) $base = '/upload';
        $path = ltrim($path, '/');
        return rtrim($base, '/') . '/' . (str_starts_with($path,'upload/') ? substr($path,7) : $path);
    }

    private static function validPublicBase(string $url): bool
    {
        return strlen($url) <= 400 && !preg_match('/[\x00-\x20\x7f<>\x22\x27`\\\\]/', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            && preg_match('~^https?://~i', $url) && filter_var($url, FILTER_VALIDATE_URL)
            && parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null
            && parse_url($url, PHP_URL_QUERY) === null && parse_url($url, PHP_URL_FRAGMENT) === null;
    }

    /** File operations are limited to ordinary image names, never upload control files. */
    public static function requireImagePath(string $path): void
    {
        if (self::localPath('/' . $path) !== $path
            || !preg_match('~^upload/(?:[a-zA-Z0-9_-][a-zA-Z0-9_.-]*/)*[a-zA-Z0-9_-][a-zA-Z0-9_.-]*\.(?:jpe?g|png|gif|webp)$~iD', $path)
            || preg_match('~\.(?:php\d*|phtml|phar|pht|phps|cgi|pl|py|sh|asp|aspx|jsp|shtml)(?:\.|/|$)~i', $path)) {
            throw new \InvalidArgumentException('仅允许处理上传目录中的图片文件，不能操作隐藏文件、脚本或控制文件');
        }
    }

    public static function localPath(string $url): string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $path = ltrim($url, '/');
        if (!str_starts_with($path, 'upload/')) {
            $settings = self::settings();
            $bases = array_merge([$settings['local_url'], (string)(_SERVER('conf')['upload_url'] ?? '/upload/')], $settings['local_aliases']);
            $site = rtrim((string)(_SERVER('conf')['siteurl'] ?? ''), '/');
            if ($site) $bases[] = $site . '/upload';
            // Host is only used to recognize our own static files, never as a download destination.
            $host = (string)($_SERVER['HTTP_HOST'] ?? '');
            if ($host !== '') { $bases[] = 'http://' . $host . '/upload'; $bases[] = 'https://' . $host . '/upload'; }
            $path = '';
            foreach ($bases as $base) if ($base && str_starts_with($url, rtrim($base, '/') . '/')) { $path = 'upload/' . substr($url, strlen(rtrim($base, '/')) + 1); break; }
        }
        if (!preg_match('~^upload/[a-zA-Z0-9_./-]+$~D', $path) || str_contains($path, '..') || str_contains($path, '//')) return '';
        return $path;
    }

    public static function absolute(string $path): string
    {
        self::requireImagePath($path);
        $root = realpath(APP_PATH . 'upload');
        $probe = APP_PATH . $path;
        while (!file_exists($probe) && dirname($probe) !== $probe) $probe = dirname($probe);
        $real = realpath($probe);
        if (!$root || !$real || ($real !== $root && !str_starts_with($real, $root . DIRECTORY_SEPARATOR))) throw new \RuntimeException('图片目录超出允许范围');
        return APP_PATH . $path;
    }

    public static function imageInfo(string $file): array
    {
        $size = is_file($file) ? filesize($file) : 0;
        $info = $size && $size <= self::MAX_BYTES ? @getimagesize($file) : false;
        $types = [IMAGETYPE_JPEG=>'jpg', IMAGETYPE_PNG=>'png', IMAGETYPE_GIF=>'gif', IMAGETYPE_WEBP=>'webp'];
        if (!$info || !isset($types[$info[2]]) || $info[0] * $info[1] > 40000000) throw new \RuntimeException('图片格式无效、超过 8MB 或尺寸过大');
        return ['bytes'=>$size, 'sha256'=>hash_file('sha256', $file), 'ext'=>$types[$info[2]]];
    }

    public static function temporary(): string
    {
        $dir = APP_PATH . 'runtime/tmp';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $file = tempnam($dir, 'image_');
        if ($file === false) throw new \RuntimeException('无法创建图片临时文件');
        return $file;
    }

    public static function download(string $url, string $file, bool $allowMissing = false): ?array
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (str_starts_with($url, '//')) $url = 'https:' . $url;
        $r = http_download_to_file($url, $file, self::MAX_BYTES, 20, 5, '', 20);
        if (empty($r['ok'])) {
            if ($allowMissing && ($r['error']??'')==='http_error' && in_array((int)($r['http_code']??0),[404,410],true)) return null;
            if ($allowMissing && (int)($r['http_code']??0)===200 && in_array($r['error']??'',['empty','too_big'],true)) return null;
            $messages=['timeout'=>'图片下载超时（20 秒），已保留原链接，可稍后重试','http_error'=>'来源返回 HTTP '.(int)($r['http_code']??0).'，已保留原链接','too_big'=>'图片超过 8MB，已保留原链接','empty'=>'来源返回空文件，已保留原链接','write_failed'=>'图片写入失败，请检查目录权限或磁盘空间','open_failed'=>'无法创建下载文件，请检查目录权限或磁盘空间'];
            throw new \RuntimeException($messages[$r['error']??'']??'图片下载失败，请检查来源是否可访问；原链接已保留');
        }
        try { return self::imageInfo($file); }
        catch (\RuntimeException $e) {
            // HTTP succeeded, but these bytes are not a usable image. A pending
            // upload may be repaired from its verified local checkpoint.
            if ($allowMissing) return null;
            throw $e;
        }
    }

    private static function ftpOptions(array $c): array
    {
        $opts = [CURLOPT_USERNAME=>$c['user'], CURLOPT_PASSWORD=>$c['password'], CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>$c['timeout'], CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_FTP, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_FTP_SKIP_PASV_IP=>true];
        if ($c['tls']) $opts[CURLOPT_USE_SSL] = CURLUSESSL_ALL;
        if (!$c['passive']) $opts[CURLOPT_FTPPORT] = '-';
        return $opts;
    }

    /** A 550 reply also means permission denied; only a successful listing proves absence. */
    private static function ftpMissing(array $c, string $path): bool
    {
        $parts = array_values(array_filter(explode('/', trim($path, '/')), static fn($v)=>$v!==''));
        $deadline = microtime(true) + $c['timeout'];
        while ($parts && count($parts)<=32 && microtime(true)<$deadline) {
            $name = array_pop($parts);
            $directory = implode('/', array_map('rawurlencode', $parts));
            $ch = curl_init('ftp://' . $c['host'] . ':' . $c['port'] . '/' . ($directory!==''?$directory.'/':''));
            $listing = '';
            $opts = self::ftpOptions($c);
            $opts[CURLOPT_TIMEOUT_MS] = max(1, (int)(($deadline-microtime(true))*1000));
            $opts[CURLOPT_DIRLISTONLY] = true;
            $opts[CURLOPT_WRITEFUNCTION] = static function($ch, $chunk) use (&$listing) {
                if (strlen($listing)+strlen($chunk)>2097152) return 0;
                $listing .= $chunk;
                return strlen($chunk);
            };
            curl_setopt_array($ch, $opts);
            $ok = curl_exec($ch)!==false;
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($ok) {
                foreach (explode("\n", $listing) as $entry) {
                    if (strcasecmp(basename(rtrim($entry,"\r")), $name)===0) return false;
                }
                return true;
            }
            // An inaccessible directory is not evidence of a missing file. Check its
            // parent instead; if the directory exists, preserve the deletion error.
            if ($code!==550) return false;
        }
        return false;
    }

    private static function ftp(array $c, string $path = '', ?string $file = null, bool $remove = false): void
    {
        if ($path !== '') self::requireImagePath($path);
        self::requireFtp($c);
        $remote = trim($c['directory'], '/') . ($path !== '' ? '/' . $path : '/');
        $remote = implode('/', array_map('rawurlencode', explode('/', ltrim($remote, '/'))));
        $ch = curl_init('ftp://' . $c['host'] . ':' . $c['port'] . '/' . ($remove ? '' : $remote));
        $stream = null;
        try {
            $opts = self::ftpOptions($c);
            // Restrict transport to the administrator's explicitly configured FTP server.
            if ($file !== null) {
                $opts[CURLOPT_FTP_CREATE_MISSING_DIRS] = CURLFTP_CREATE_DIR_RETRY;
                $stream = fopen($file, 'rb');
                $opts[CURLOPT_UPLOAD] = true;
                $opts[CURLOPT_INFILE] = $stream;
                $opts[CURLOPT_INFILESIZE] = filesize($file);
            } else $opts[CURLOPT_NOBODY] = true;
            if ($remove) $opts[CURLOPT_QUOTE] = ['DELE /' . rawurldecode($remote)];
            curl_setopt_array($ch, $opts);
            if (curl_exec($ch) === false) {
                $error = curl_errno($ch);
                if ($remove && $error===CURLE_FTP_QUOTE_ERROR && (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE)===550 && self::ftpMissing($c,rawurldecode($remote))) return;
                throw new \RuntimeException('FTP 操作失败（错误 ' . $error . '），请检查账号、目录权限及连接模式');
            }
        } finally { if (is_resource($stream)) fclose($stream); }
    }

    public static function upload(array $c, string $path, string $file, ?callable $onUploaded = null): string
    {
        self::ftp($c, $path, $file);
        $url = rtrim($c['public_url'], '/') . '/' . $path;
        // Persist STOR completion before an HTTP timeout can hide that success.
        if ($onUploaded) $onUploaded();
        $tmp = self::temporary();
        try {
            $remote = self::download($url, $tmp);
            if (!hash_equals(hash_file('sha256', $file), $remote['sha256'])) throw new \RuntimeException('FTP 已上传，但图片访问地址返回的内容不一致；已保留本地图片');
        } finally { @unlink($tmp); }
        return $url;
    }

    public static function probe(array $input, bool $upload): string
    {
        $c = self::normalize($input);
        if (!$upload) { self::ftp($c); return 'FTP 连接成功'; }
        $file = self::temporary();
        $path = 'upload/probe/' . bin2hex(random_bytes(12)) . '.png';
        try {
            file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
            self::upload($c, $path, $file);
            return '测试上传成功，图片访问地址校验通过';
        } finally { @unlink($file); try { self::ftp($c, $path, null, true); } catch (\Throwable $e) { xn_log('attachment probe cleanup failed', 'attachment_error'); } }
    }

    public static function removeRemote(array $profile, string $path): void { self::ftp($profile, $path, null, true); }

    public static function verify(array $asset, bool $restoreLocal = false, bool $allowRepair = false): ?bool
    {
        $tmp = self::temporary();
        try {
            $info = self::download($asset['url'], $tmp, $allowRepair);
            if ($info===null) return null;
            if ($asset['sha256'] === '' || !hash_equals($asset['sha256'], $info['sha256'])) {
                if ($allowRepair) return null;
                throw new \RuntimeException('已上传图片的访问校验失败，保留原引用和本地副本');
            }
            if($restoreLocal && $asset['path']!=='') {
                return AttachmentSchema::lock('delete:'.$asset['path'],static function() use($asset,$tmp,$info) {
                    $file=self::absolute($asset['path']);clearstatcache(true,$file);
                    if(is_file($file)&&hash_file('sha256',$file)===$info['sha256']) return false;
                    if(!is_dir(dirname($file))) mkdir(dirname($file),0755,true);
                    if(!rename($tmp,$file)) throw new \RuntimeException('FTP 图片可访问，但无法恢复本地副本，请检查目录权限');
                    return true;
                });
            }
            return false;
        } finally { @unlink($tmp); }
    }
}
