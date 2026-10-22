<?php
/**
 * File 函数
 * 从 misc.php 拆分
 */

/**
 * HTTP GET请求（自动选择curl或file_get_contents）
 * @param string $url 请求地址
 * @param string $cookie Cookie字符串
 * @param int $timeout 超时时间（秒）
 */
// 安全请求一个 URL
// ini_set('default_socket_timeout', 60);
// 将变量写入到文件，根据后缀判断文件格式，先备份，再写入，写入失败，还原备份
// ============================================================================
// [FILE] 文件操作函数
// ============================================================================

function file_replace_var($filepath, $replace = array(), $pretty = FALSE)
{
    $ext = file_ext($filepath);
    if ('php' == $ext) {
        // 文件不存在/不可读/内容不是 return array(...) 时返回 FALSE，由调用方给出明确提示，
        // 不再把 include 的 FALSE 直接喂给 array_merge()（PHP 8 下 TypeError → 空白 500）
        if (!is_file($filepath) || !is_readable($filepath)) return FALSE;
        $arr = include $filepath;
        if (!is_array($arr)) return FALSE;
        $arr = array_merge($arr, $replace);
        $s = "<?php\r\nreturn " . var_export($arr, true) . ";\r\n?>";
        // 备份文件
        file_backup($filepath);
        $r = file_put_contents_try($filepath, $s);
        $r != strlen($s) ? file_backup_restore($filepath) : file_backup_unlink($filepath);
        // 写入成功后清除 OPcache，确保后续请求读到最新配置
        if ($r && function_exists('opcache_invalidate')) {
            opcache_invalidate($filepath, true);
        }
        return $r;
    } elseif ('js' == $ext || 'json' == $ext) {
        $s = file_get_contents_try($filepath);
        $arr = xn_json_decode($s);
        if (empty($arr)) return FALSE;
        $arr = array_merge($arr, $replace);
        $s = xn_json_encode($arr, $pretty);
        file_backup($filepath);
        $r = file_put_contents_try($filepath, $s);
        $r != strlen($s) ? file_backup_restore($filepath) : file_backup_unlink($filepath);
        return $r;
    }
}

/**
 * 推导备份文件路径：`file_pre(原路径) . '.backup.' . file_ext(原路径)`，即同目录同名加 .backup 后缀。
 * file_ext() 会把后缀小写、xn_urlencode 清洗、限长 16 并做 `^\w+$` 白名单（不合法落 'attach'）⇒ 备份名不含原始大写/特殊后缀。
 * ⚠ 边界（实测）：路径里没有 `.` 时 file_pre() 的 substr(0, strrpos=FALSE) 归 0 回 ''，
 *   file_ext() 回 'attach' ⇒ 结果退化成当前目录下的 `.backup.attach`（会丢目录与文件名）。
 *   调用方（file_backup 系列）都是带扩展名的配置/数据文件，故未暴露；新增用法注意。
 * @param string $filepath 原文件路径
 * @return string 备份文件路径（纯字符串拼接，不检查文件是否存在）
 */
function file_backname($filepath)
{
    $filepre = file_pre($filepath);
    $fileext = file_ext($filepath);
    $s = "$filepre.backup.$fileext";
    return $s;
}


// 备份文件
function file_backup($filepath)
{
    $backfile = file_backname($filepath);
    if (is_file($backfile)) return TRUE; // 备份已经存在
    $r = xn_copy($filepath, $backfile);
    clearstatcache();
    return $r && filesize($backfile) == filesize($filepath);
}

// 还原备份
function file_backup_restore($filepath)
{
    $backfile = file_backname($filepath);
    $r = xn_copy($backfile, $filepath);
    clearstatcache();
    $r && filesize($backfile) == filesize($filepath) && xn_unlink($backfile);
    return $r;
}

// 删除备份
function file_backup_unlink($filepath)
{
    $backfile = file_backname($filepath);
    $r = xn_unlink($backfile);
    return $r;
}

/**
 * 带重试的二进制读文件：最多 $times 次 fopen，失败每次 sleep(1)（⚠ 渲染期不要调，会阻塞 FPM worker）。
 * 一次 fread 读完 filesize() 报告的字节数；空文件直接返回 ''（不报错）。
 * 与 file_get_contents() 的差别只在重试与显式 open/read，不做 filesize 上限保护 ⇒ 大文件照样整包入内存。
 * @param string $file  文件路径
 * @param int    $times 尝试次数（默认 3；传 1 即不重试）
 * @return string|FALSE 文件内容（'' 表示空文件）；$times 次都没打开成功返回 FALSE
 */
function file_get_contents_try($file, $times = 3)
{
    while ($times-- > 0) {
        $fp = fopen($file, 'rb');
        if ($fp) {
            $size = filesize($file);
            if (0 == $size) return '';
            $s = fread($fp, $size);
            fclose($fp);
            return $s;
        } else {
            sleep(1);
        }
    }
    return FALSE;
}

/**
 * 原子安全写文件（本项目所有配置/编译产物落盘的统一入口）：
 * 目录缺失先递归 mkdir ⇒ 写 `原名.tmp.<pid>.<随机>` 临时文件（排他锁 flock LOCK_EX）⇒ rename 到位，
 * rename 失败退化为 xn_copy + unlink 临时文件。失败重试 $times 次，每次 sleep(1)。
 * 关键语义：返回值是**写入字节数**而非布尔，所以调用方按 `$r != strlen($s)` 判失败（见 file_replace_var）；
 * 目标不可写/rename 与 copy 都失败 ⇒ FALSE。临时文件名带 pid+随机数，避免并发写同一目标互相踩。
 * @param string $file  目标路径
 * @param string $s     写入内容
 * @param int    $times 尝试次数（默认 3）
 * @return int|FALSE 成功=fwrite 写入字节数；失败=FALSE
 */
function file_put_contents_try($file, $s, $times = 3)
{
    while ($times-- > 0) {
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $tmp = $file . '.tmp.' . getmypid() . '.' . mt_rand(100000, 999999);
        $fp = fopen($tmp, 'wb');
        if ($fp && flock($fp, LOCK_EX)) {
            $n = fwrite($fp, $s);
            fflush($fp);
            version_compare(PHP_VERSION, '5.3.2', '>=') and flock($fp, LOCK_UN);
            fclose($fp);
            $ok = @rename($tmp, $file);
            if (!$ok) {
                $ok = xn_copy($tmp, $file);
                @unlink($tmp);
            }
            clearstatcache();
            return $ok ? $n : FALSE;
        } else {
            $fp && fclose($fp);
            @unlink($tmp);
            sleep(1);
        }
    }
    return FALSE;
}


// 文件后缀名，不包含 .
function file_ext($filename, $max = 16)
{
    $ext = strtolower(substr(strrchr($filename, '.'), 1));
    $ext = xn_urlencode($ext);
    strlen($ext) > $max and $ext = substr($ext, 0, $max);
    if (!preg_match('#^\w+$#', $ext)) $ext = 'attach';
    return $ext;
}

// 文件的前缀，不包含最后一个 .
function file_pre($filename, $max = 32)
{
    return substr($filename, 0, strrpos($filename, '.'));
}

// 获取路径中的文件名
function file_name($path)
{
    return substr($path, strrpos($path, '/') + 1);
}

// 递归遍历目录
function glob_recursive($pattern, $flags = 0)
{
    $files = glob($pattern, $flags);
    foreach (glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
        $files = array_merge($files, glob_recursive($dir . '/' . basename($pattern), $flags));
    }
    return $files;
}

// 递归删除目录，这个函数比较危险，传参一定要小心
function rmdir_recusive($dir, $keepdir = 0)
{
    if ('/' == $dir || './' == $dir || '../' == $dir) return FALSE; // 不允许删除根目录，避免程序意外删除数据。
    if (!is_dir($dir)) return FALSE;
    '/' != substr($dir, -1) and $dir .= '/';
    $files = glob($dir . '*'); // +glob($dir.'.*')
    foreach (glob($dir . '.*') as $v) {
        if (substr($v, -1) != '.' && substr($v, -2) != '..') $files[] = $v;
    }
    $filearr = $dirarr = array();
    if ($files) {
        foreach ($files as $file) {
            if (is_dir($file)) {
                $dirarr[] = $file;
            } else {
                $filearr[] = $file;
            }
        }
    }
    if ($filearr) {
        foreach ($filearr as $file) {
            xn_unlink($file);
        }
    }
    if ($dirarr) {
        foreach ($dirarr as $file) {
            rmdir_recusive($file);
        }
    }
    if (!$keepdir) xn_rmdir($dir);
    return TRUE;
}

/**
 * 安全 copy()：先 is_file() 挡掉目录/不存在路径（源是目录时 copy() 会发出 warning 并回 FALSE，这里直接 FALSE）。
 * 目标已存在则覆盖；⚠ copy() **不保留**源文件 mtime 与权限位（实测目标 mtime=复制时刻、权限按默认 umask 落盘），
 * 依赖时间戳/权限做后续判断（如 file_backup 的 filesize 对比）时注意这一点。
 * @param string $src  源文件路径
 * @param string $dest 目标路径
 * @return bool 成功=copy() 的 TRUE；源不是常规文件或复制失败=FALSE
 */
function xn_copy($src, $dest)
{
    $r = is_file($src) ? copy($src, $dest) : FALSE;
    return $r;
}


/**
 * 安全 rmdir()：先 is_dir() 判定，避免对不存在路径/文件发 warning。仅能删**空目录**（rmdir 语义）。
 * @param string $dir 目录路径
 * @return bool 成功删除=TRUE；不是目录或 rmdir 失败=FALSE
 */
function xn_rmdir($dir)
{
    $r = is_dir($dir) ? rmdir($dir) : FALSE;
    return $r;
}

/**
 * 安全 unlink()：先 is_file() 判定，路径不存在或是目录时直接 FALSE（不发 warning、不动权限）。
 * ⚠ is_file() 跟随符号链接：指向常规文件的软链会被判为文件并 unlink（删掉的是软链本身，非目标）。
 * @param string $file 文件路径
 * @return bool 删除成功=TRUE；否则 FALSE
 */
function xn_unlink($file)
{
    $r = is_file($file) ? unlink($file) : FALSE;
    return $r;
}


// 取得路径：001/123

// 递归拷贝目录
/**
 * 递归复制目录
 *
 * 健壮性修复（升级"假成功"真机实测）：
 *  ① **覆盖失败先 unlink 再重试**——`copy()` 判定的是**目标文件**可写位；站点若混有 root 属主文件
 *     （手工 SFTP/面板以 root 上传、早期 root 解包，实测测试站有 501 个 644 root:root），www 覆盖
 *     必然失败，而旧实现**静默跳过** ⇒ 升级"成功"了但文件没变。改为：`copy()` 失败时先 `@unlink()`
 *     （删文件只需**父目录**可写，站点根通常对 www 可写）再复制一次，新文件由 www 属主落盘。
 *  ② 失败**不再静默**：可选的 `$errors` 收集器（引用传入）记录无法落盘的路径，供调用方（云端升级）
 *     据此判定"升级不完整"并报错，而不是回一个 success。既有调用方不传该参数 ⇒ 行为与旧版一致。
 *
 * @param string $src 源目录
 * @param string $dst 目标目录
 * @param array|null $errors 失败路径收集器（按引用累加）
 */
function copy_recusive($src, $dst, &$errors = NULL)
{
    '/' == substr($src, -1) and $src = substr($src, 0, -1);
    '/' == substr($dst, -1) and $dst = substr($dst, 0, -1);
    $dir = opendir($src);
    if (!is_dir($dst) && !@mkdir($dst, 0777, TRUE) && !is_dir($dst)) {
        is_array($errors) and $errors[] = $dst . '（目录无法创建）';
        closedir($dir);
        return;
    }
    while (FALSE !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            if (is_dir($src . '/' . $file)) {
                copy_recusive($src . '/' . $file, $dst . '/' . $file, $errors);
            } else {
                $ok = xn_copy($src . '/' . $file, $dst . '/' . $file);
                if (!$ok) {
                    // 目标可能是 root 属主/只读文件：先删（父目录可写即可）再复制
                    @unlink($dst . '/' . $file);
                    $ok = xn_copy($src . '/' . $file, $dst . '/' . $file);
                }
                if (!$ok && is_array($errors)) $errors[] = $dst . '/' . $file;
            }
        }
    }
    closedir($dir);
}

// 随机字符
function xn_rand($n = 16)
{
    // 优先 CSPRNG（文件名/别名不可预测性，审计修复）；低版本回退 mt_rand
    $str = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    $len = strlen($str);
    $return = '';
    $bytes = function_exists('random_bytes') ? random_bytes($n) : '';
    if ($bytes !== '') {
        for ($i = 0; $i < $n; $i++) {
            $return .= $str[ord($bytes[$i]) % $len];
        }
        return $return;
    }
    for ($i = 0; $i < $n; $i++) {
        $r = mt_rand(1, $len);
        $return .= $str[$r - 1];
    }
    return $return;
}

// 检测文件是否可写，兼容 windows
function xn_is_writable($file)
{
    if (PHP_OS != 'WINNT') {
        return is_writable($file);
    } else {
        // 如果是 windows，比较麻烦，这也只是大致检测，不够精准。
        if (is_file($file)) {
            $fp = fopen($file, 'a+');
            if (!$fp) return FALSE;
            fclose($fp);
            return TRUE;
        } elseif (is_dir($file)) {
            $tmpfile = $file . uniqid() . '.tmp';
            $r = touch($tmpfile);
            if (!$r) return FALSE;
            if (!is_file($tmpfile)) return FALSE;
            xn_unlink($tmpfile);
            return TRUE;
        } else {
            return FALSE;
        }
    }
}

// 解码客户端提交的 base64 数据
function base64_decode_file_data($data)
{
    if ('data:' == substr($data, 0, 5)) {
        $data = substr($data, strpos($data, ',') + 1);    // 去掉 data:image/png;base64,
    }
    $data = base64_decode($data);
    return $data;
}

// 逐行读取
function bull_import($file)
{
    if ($handle = fopen($file, 'r')) {
        while (!feof($handle)) {
            yield trim(fgets($handle));
        }
        fclose($handle);
    }
}

// 计算总行数
function bull_import_total($file, $key = 'bull_import_total')
{
    static $cache = array();
    if (isset($cache[$key])) return $cache[$key];
    $count = cache_get($key);
    if (NULL === $count) {
        $count = 0;
        $globs = bull_import($file);
        while ($globs->valid()) {
            ++$count;
            $globs->next(); // 指向下一个
        }
        $count and cache_set($key, $count, 300);
    }
    return $cache[$key] = $count;
}

/**
 * 递归收集目录下文件路径到全局 $g_dir_file（深度优先，跳过 . / ..，遇目录继续下钻）。
 * ⚠ 实测行为与「收集全部文件」不符：递归内层调用会在开头把**同一个全局**重新置空
 * （构造 目录A/1.txt + 子目录B/2.txt 时实测只返回 B/2.txt），于是每进入一个子目录，
 * 之前已收集的路径就被丢弃 ⇒ 返回值只含「最后一个被访问的子树」以及其后追加的文件。
 * 需要可靠的全量清单请改用 glob_recursive() 或 PHP 内置 RecursiveDirectoryIterator。
 * 不做符号链接判定、不限制深度与文件数 ⇒ 大目录/软链环有耗时与循环风险。
 * 仓内当前无调用点（仅自身递归），但函数名带 bull_ 前缀、可能被插件按名调用，勿轻易改签名。
 * @param string $path 目录路径；不是目录时返回空数组（全局仍会被重置为 array()）
 * @return array 文件路径列表（形如 path/sub/file.php，键为 0..n）
 */
function bull_search_dir($path)
{
    global $g_dir_file;
    // Always reset to empty on each call to prevent stale data from prior calls
    $g_dir_file = array();
    if (is_dir($path)) {
        $paths = scandir($path);
        foreach ($paths as $val) {
            $sub_path = $path . '/' . $val;
            if ('.' == $val || '..' == $val) {
                continue;
            } else if (is_dir($sub_path)) {
                bull_search_dir($sub_path);
            } else {
                $g_dir_file[] = $sub_path;
            }
        }
    }
    return $g_dir_file;
}

