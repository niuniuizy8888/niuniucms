<?php
/**
 * 核心工具函数库
 *
 * 本文件按职责分为以下区域（通过注释标记定位）：
 * [PAGINATION]  分页函数（page_array/pagination/index_page/pager）
 * [PARAM]       参数获取函数（param/param_word/bull_param 等）
 * [SECURITY]    安全函数（password_hash/verify/bull_token/xn_html_safe 等）
 * [URL]         URL 函数（url/url_sitemap/xn_url_parse/http_url_path 等）
 * [FILE]        文件操作函数（file_replace_var/rmdir_recusive/xn_mkdir 等）
 * [HTTP]        HTTP 函数（http_404/http_location/https_request 等）
 * [STRING]      字符串函数（xn_strlen/xn_substr/xn_rand 等）
 * [DISPLAY]     显示函数（message/jump/humandate/pagination 等）
 * [MISC]        其他工具函数（xn_log/get_device/check_runlevel 等）
 */

// 加载拆分模块
include_once __DIR__ . '/pagination.php';
include_once __DIR__ . '/param.php';
include_once __DIR__ . '/security.php';
include_once __DIR__ . '/url.php';
include_once __DIR__ . '/file.php';

// ============================================================================
// [PAGINATION] 分页函数
// ============================================================================

//2026-02-17

// ============================================================================
// [STRING] 字符串函数
// ============================================================================

/**
 * 获取用户IP并转为长整型
 * @return int|string IP长整型值
 */
function bull_longip()
{
    $ip = ip();
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $longip = ip2long($ip);
        // fix 32 位 OS 下溢出的问题
        $longip < 0 and $longip = sprintf("%u", $longip);
    } else {
        // 避免使用需要GMP/BCMATH扩展的函数
        $longip = 0;
    }
    return $longip;
}
/**
 * 输出消息（支持JSON或纯文本）
 * @param int $code 状态码
 * @param string $message 消息内容
 */
// ============================================================================
// [DISPLAY] 显示与消息函数
// ============================================================================

/**
 * 全局未捕获异常处理器（原注释：捕获全局异常，即业务代码里 throw new Exception('...') 的那一类）。
 * 由 core/core.php 用 set_exception_handler() 注册，且**注册本身带 DEBUG 前置**（`DEBUG and ...`），
 * 线上 DEBUG=0 时本函数根本不会被挂上，异常走 PHP 默认处理；函数体内仍保留
 * 「DEBUG 才给详情、否则只给 lang('server_error')」的分支，属双保险（红线 §二.10：
 * 栈与服务器路径不得外泄，线上口径不要改）。
 * 输出形态按通道分三种：JSON 模式（_SERVER('json')）与 CLI（IN_CMD）输出纯文本；
 * 浏览器输出 fieldset 提示块，内容先 htmlspecialchars(ENT_QUOTES, UTF-8)。
 * 落盘口径：只有 DEBUG == 2 才 xn_log 到 debug_error 通道（DEBUG == 1 会显示但不写日志）。
 * @param Throwable $exception 未捕获的异常/错误对象（set_exception_handler 的既定入参；
 *                             DEBUG 分支被直接当字符串拼接/输出，走 Throwable::__toString）
 * @return void 无返回值（处理器不负责恢复执行）
 */
function exception_handler($exception)
{
    $json = _SERVER('json');
    $message = DEBUG ? $exception : lang('server_error');
    $html = "<fieldset class=\"fieldset small notice\"><div>" . htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') . "</div></fieldset>";
    echo ($json || IN_CMD) ? $message : $html;
    2 == DEBUG and xn_log($exception, 'debug_error');
}
/**
 * 全局 PHP 错误处理器（由 core/core.php 以 `set_error_handler('error_handle', -1)` 注册，
 * 同样只在 DEBUG 非 0 时挂上；severity 传 -1 表示接管全部级别）。
 *
 * 原注释记着这条设计动机：**「中断流程很危险！可能会导致数据问题，线上模式不允许中断流程！」**
 * ——所以 DEBUG 模式下本函数把详情全部写日志后**返回 TRUE**（吞掉该错误、脚本继续跑），
 * 只在 DEBUG=0 时 `return FALSE` 交回 PHP 内部处理（此时本函数并未被注册，属防御性写法）。
 * 落盘两条通道：subject（错误号/文本/文件/行号）进 php_error；subject + debug_backtrace 逐帧
 * （文件、行号、函数名与参数摘要，数组只记元素个数、对象只记 object，避免把敏感实参整份写进日志）
 * 进 debug_error；CLI 下同时 fwrite 到 STDERR。响应侧不外泄任何路径信息。
 * ⚠ 不要把它改成「记完日志就 exit/抛异常」：接管全部级别时连 notice/warning 都会中断整页渲染。
 * @param int $errno 错误号（PHP 的 E_* 常量值，取证：set_error_handler 回调约定）
 * @param string $errstr 错误文本
 * @param string $errfile 出错文件绝对路径
 * @param int $errline 出错行号
 * @return bool TRUE = 已接管、抑制 PHP 内部处理与输出；FALSE = 交回 PHP 内部处理
 */
function error_handle($errno, $errstr, $errfile, $errline)
{
    // PHP 内部默认处理
    if (0 == DEBUG) return FALSE;
   $time = time();
   $message = array();
    $subject = "Error[$errno]: $errstr, File: $errfile, Line: $errline";
   xn_log($subject, 'php_error'); // 所有PHP错误报告都记录日志
    $arr = debug_backtrace();
    array_shift($arr);
    foreach ($arr as $v) {
        $args = '';
        if (!empty($v['args']) && is_array($v['args'])) foreach ($v['args'] as $v2) $args .= ($args ? ' , ' : '') . (is_array($v2) ? 'array(' . count($v2) . ')' : (is_object($v2) ? 'object' : $v2));
        !isset($v['file']) and $v['file'] = '';
        !isset($v['line']) and $v['line'] = '';
        $message[] = "File: $v[file], Line: $v[line], $v[function]($args) ";
    }
    $txt = $subject . "\r\n" . implode("\r\n", $message);
    // DEBUG 模式错误写入日志，不泄露服务器路径到响应
    xn_log($txt, 'debug_error');
    // CLI 模式下输出到 stderr
    IN_CMD and fwrite(STDERR, $txt . "\n");
    return TRUE;
}
/**
 * 用全局变量记录错误信息（原注释口径）：把错误号与错误文本写进全局 $errno / $errstr
 * （两变量在 core/core.php 里初始化为 0 / 空串），然后**返回 $return**。
 * 设计成「一行同时做完 记错误 + 给出返回值」，所以调用点清一色是 `return xn_error(-1, '原因')`
 * （取证：core/func/http.php 的 SSRF 拦截与 curl 失败分支、core/cache/cache.php 的不支持缓存类型、
 * core/db/db.php 的不支持 db.type / mysql 驱动已移除分支），错误码恒用 -1。
 * ⚠ 这只是「记账」，不抛异常、不写日志、也不中断流程：没人读 $errno/$errstr 时错误会被静默吞掉，
 * 新代码要留痕请配 xn_log()/LoggerService，或直接抛异常。
 * @param int $no 错误号（取证：调用点当前一律传 -1）
 * @param string $str 错误描述（会被原样存进全局 $errstr，注意别把凭据/内部路径写进去）
 * @param mixed $return 本函数的返回值，默认 FALSE，供调用方直接 return 出去；
 *                      调用点未见传第三参，故类型按「由调用方自定」记为 mixed
 * @return mixed 即 $return 原值（默认 FALSE）
 */
function xn_error($no, $str, $return = FALSE)
{
    global $errno, $errstr;
    $errno = $no;
    $errstr = $str;
    return $return;
}
/*
	param(1);
	param(1, '');
	param(1, 0);
	param(1, array());
	param(1, array(''));
	param(1, array(0));
*/
/*
	仅支持一维数组的类型强制转换。
	param_force($val);
	param_force($val, '');
	param_force($val, 0);
	param_force($arr, array());
	param_force($arr, array(''));
	param_force($arr, array(0));
*/
/*
	lang('mobile_length_error');
	lang('mobile_length_error', array('mobile'=>$mobile));
*/

function jump($message, $url = '', $delay = 3)
{
    $json = _SERVER('json');
    if ($json) return $message;
    if (!$url) return $message;
    'back' == $url and $url = 'javascript:history.back()';
    // 外链安全跳转：外部 URL 先经确认页（防钓鱼/防误跳），自动跳转默认 5 秒
    if (\App\Service\System\UrlSafetyService::isExternal($url)) {
        return \App\Service\System\UrlSafetyService::renderJumpConfirm($message, $url, max(1, $delay));
    }
    // JS 与 HTML 双转义：防 url 含引号时注入脚本/属性（url 可能含外部输入）
    $js_url = str_replace(array('\\', "'"), array('\\\\', "\\'"), $url);
    $js_url = str_ireplace('</script', '<\\/script', $js_url); // 防提前闭合 script 标签注入
    $html_url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $htmladd = '<script>setTimeout(function() {window.location=\'' . $js_url . '\'}, ' . ($delay * 1000) . ');</script>';
    return '<a href="' . $html_url . '">' . $message . '</a>' . $htmladd;
}
/**
 * UTF-8 字符数（mb_strlen 封装，编码固定 'UTF-8'）
 * @param string $s
 * @return int
 */
function xn_strlen($s)
{
    return mb_strlen($s, 'UTF-8');
}
/**
 * UTF-8 子串（mb_substr 封装，编码固定 'UTF-8'）
 * @param string $s
 * @param int $start 起始字符位（支持负数=自尾部计）
 * @param int|null $len 长度；NULL 取到串尾（采集标签截断即此用法）
 * @return string
 */
function xn_substr($s, $start, $len)
{
    return mb_substr($s, $start, $len, 'UTF-8');
}
/**
 * 宽容 JSON 编码：PHP 5.4 及以上直接走内置 json_encode，固定带
 * JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE（斜杠与非 ASCII 都不转义，
 * 中文与 URL 保持原形——后台 AJAX 回包、云端交互、日志 context 都依赖这个口径，
 * 换成裸 json_encode 会让响应里出现 `\/` 和 `\uXXXX`，属对外契约变化）。
 * 下方那套手写 switch/递归分支只在版本低于 5.4 时才可能执行，本站要求 PHP 8.5，
 * **现网不可达**（保留是历史包袱，别在它上面加新逻辑）；$level/$pretty 的缩进参数也只服务该分支。
 * ⚠ 上一行历史注释「txt 转换到 html」是相邻函数被移走后留下的错位说明，与本函数无关
 * （全仓无 txt 转 html 的实现，换行处理请用模板/nl2br 侧）。
 * 失败语义：内置 json_encode 对非法 UTF-8、INF/NAN、不可序列化对象会返回 FALSE 而非抛错，
 * 现有调用点（message() 出口、LoggerService 的 context）都**没有判 FALSE**。
 * @param mixed $data 待编码值（取证：调用点传 array / 标量，如 message() 的响应数组、日志 context）
 * @param bool $pretty 是否美化输出（仅 5.4+ 分支生效：加 JSON_PRETTY_PRINT）
 * @param int $level 递归缩进层数，仅供已不可达的低版本分支使用
 * @return string|false JSON 文本；编码失败为 FALSE（低版本分支遇不支持类型返回空串）
 */
function xn_json_encode($data, $pretty = FALSE, $level = 0)
{
    if (version_compare(PHP_VERSION, '5.4.0') >= 0) {
        return $pretty ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    $tab = $pretty ? str_repeat("\t", $level) : '';
    $tab2 = $pretty ? str_repeat("\t", $level + 1) : '';
    $br = $pretty ? "\r\n" : '';
    switch ($type = gettype($data)) {
        case 'NULL':
            return 'null';
        case 'boolean':
            return ($data ? 'true' : 'false');
        case 'integer':
        case 'double':
        case 'float':
            return $data;
        case 'string':
            $data = '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), $data) . '"';
            $data = str_replace("\r", '\\r', $data);
            $data = str_replace("\n", '\\n', $data);
            $data = str_replace("\t", '\\t', $data);
            return $data;
        case 'object':
            return get_object_vars($data);
        case 'array':
            $output_index_count = 0;
            $output_indexed = array();
            $output_associative = array();
            foreach ($data as $key => $value) {
                $output_indexed[] = xn_json_encode($value, $pretty, $level + 1);
                $output_associative[] = $tab2 . '"' . $key . '":' . xn_json_encode($value, $pretty, $level + 1);
                if (NULL !== $output_index_count && $output_index_count++ !== $key) {
                    $output_index_count = NULL;
                }
            }
            if (NULL !== $output_index_count) {
                return '[' . implode(",$br", $output_indexed) . ']';
            } else {
                return "{{$br}" . implode(",$br", $output_associative) . "{$br}{$tab}}";
            }
        default:
            return ''; // Not supported
    }
}
/**
 * 宽容 JSON 解码：先剥 UTF-8 BOM（\xEF\xBB\xBF）与 UTF-16 BOM 残字节（\xFE/\xFF），
 * 再 json_decode 转关联数组（应对云端/采集源响应带 BOM 头）
 * @param string $json JSON 文本（非字符串入参会被 trim 强转，调用方须传 string）
 * @return mixed 合法 JSON→array/标量；非法→null（调用方多以 is_array 判成败）
 */
function xn_json_decode($json)
{
    $json = trim($json, "\xEF\xBB\xBF");
    $json = trim($json, "\xFE\xFF");
    return json_decode($json, TRUE);
}
/**
 * 生成分页条 HTML（原注释：bootstrap 翻页，命名与 bootstrap 保持一致——页签 class 走
 * `active` 后缀，前后用「« / »」做上一页、下一页，结构由 pagination_tpl() 出片）。
 * 滑动窗口固定 3 页半径，并在贴边时把缺口补到另一侧，保证窗口宽度稳定；
 * 首页/末页与省略号（`...`）在窗口外单独补位。
 *
 * URL 口径：$url 先 xn_urldecode + trim，再把占位符 {page} 换成页码 ⇒ 传进来的是
 * url()/ 生成的模板串（含 {page}），不是已编码的成品链接。
 * 边界：总页数小于 2 直接返回空串（调用方无需自己判空）；$page 会被 min() 钳到末页，
 * 但**不会**钳下限，传 0/负数会得到「上一页」指向负页码的链接（前台路由各自负责入参规整）。
 * 转义边界（红线 §二.4）：拼进 href 的 URL 不做转义，页码是内部整数所以安全；
 * 若把本函数产出的 HTML 直插模板，注意模板 `{$var}` 是裸 echo，别再套一层转义。
 * @param string $url URL 模板，{page} 会被替换为页码（取证：调用点传 url()/url_domain() 生成的串）
 * @param int $totalnum 总记录数
 * @param int $page 当前页码（1 起）
 * @param int $pagesize 每页条数，默认 20
 * @return string 分页条 HTML；总页数不足 2 页时为空串
 */
function pagination($url, $totalnum, $page, $pagesize = 20)
{
    $url = trim(xn_urldecode($url));
    $totalpage = ceil($totalnum / $pagesize);
    if ($totalpage < 2) return '';
    $page = min($totalpage, $page);
    $shownum = 3; // 显示多少个页 * 2
    $start = max(1, $page - $shownum);
    $end = min($totalpage, $page + $shownum);
    // 不足 $shownum，补全左右两侧
    $right = $page + $shownum - $totalpage;
    $right > 0 && $start = max(1, $start -= $right);
    $left = $page - $shownum;
    $left < 0 && $end = min($totalpage, $end -= $left);
    $s = '';
    $page != 1 && $s .= pagination_tpl(str_replace('{page}', $page - 1, $url), '&laquo;', '');
    if ($start > 1) $s .= pagination_tpl(str_replace('{page}', 1, $url), '1 ' . ($start > 2 ? '...' : ''));
    for ($i = $start; $i <= $end; $i++) {
        $s .= pagination_tpl(str_replace('{page}', $i, $url), $i, $i == $page ? ' active' : '');
    }
    if ($end != $totalpage) $s .= pagination_tpl(str_replace('{page}', $totalpage, $url), ($totalpage - $end > 1 ? '...' : '') . $totalpage);
    $page != $totalpage && $s .= pagination_tpl(str_replace('{page}', $page + 1, $url), '&raquo;');
    return $s;
}
/**
 * 相对时间人性化显示（如「3天前」「12秒前」；超一年退化为日期）
 * 插件可定义 custom_humandate() 整体接管本函数逻辑（static 探测一次）。
 * 文案数组优先级：$_SERVER['lang'] 语言包顶层键（month_ago 等）非空时用之 > 入参 $lan > 内置中文默认。
 * ⚠ en_us 语言包实测缺这五个 *_ago 键（仅 28 键桩）：en_us 站点走本函数会报 Undefined array key
 * 并输出光秃数字（zh_cn 完整，不受影响）。
 * @param int $timestamp 事件 Unix 时间戳（基准取容器 time，§二.5）
 * @param array $lan 备用文案键组（month_ago/day_ago/hour_ago/minute_ago/second_ago）
 * @return string 展示文本（注意：$timestamp 在未来时 $seconds 为负，落到「秒前」分支输出负数）
 */
function humandate($timestamp, $lan = array())
{
    $time = _SERVER('time');
    $lang = _SERVER('lang');
    is_array($lan) || $lan = array();
    is_array($lang) || $lang = array();
    static $custom_humandate = NULL;
    if (NULL === $custom_humandate) $custom_humandate = function_exists('custom_humandate');
    if ($custom_humandate) return custom_humandate($timestamp, $lan);
    $seconds = $time - $timestamp;
    $lan = empty($lang) ? $lan : $lang;
    empty($lan) and $lan = array(
        'month_ago' => '月前',
        'day_ago' => '天前',
        'hour_ago' => '小时前',
        'minute_ago' => '分钟前',
        'second_ago' => '秒前',
    );
    if ($seconds > 31536000) {
        return date('Y-n-j', $timestamp);
    } elseif ($seconds > 2592000) {
        return floor($seconds / 2592000) . $lan['month_ago'];
    } elseif ($seconds > 86400) {
        return floor($seconds / 86400) . $lan['day_ago'];
    } elseif ($seconds > 3600) {
        return floor($seconds / 3600) . $lan['hour_ago'];
    } elseif ($seconds > 60) {
        return floor($seconds / 60) . $lan['minute_ago'];
    } else {
        return $seconds . $lan['second_ago'];
    }
}
/**
 * 字节数人性化（1024 进制）：G/M/K 两位小数，其余原值+B
 * 阈值为严格大于——恰 1024/1048576/1073741824 落在较小单位档（1024 → "1024B"）。
 * @param int|float $num 字节数
 * @return string 如 "3.50M"（number_format 结果恒带两位小数）
 */
function humansize($num)
{
    if ($num > 1073741824) {
        return number_format($num / 1073741824, 2, '.', '') . 'G';
    } elseif ($num > 1048576) {
        return number_format($num / 1048576, 2, '.', '') . 'M';
    } elseif ($num > 1024) {
        return number_format($num / 1024, 2, '.', '') . 'K';
    } else {
        return $num . 'B';
    }
}
/**
 * 获取客户端IP地址
 * 开启CDN时信任 CDN 转发的头信息
 * @return string IP地址
 */
// 不安全的获取 IP 方式，在开启 CDN 的时候，如果被人猜到真实 IP，则可以伪造。
/**
 * 记录日志（原注释「日志记录」）：兼容壳，转发 `App\Service\System\LoggerService::write()`，
 * 让历史上约 67 处 `xn_log($msg, '通道')` 调用点不用改；新代码请直接用 LoggerService::channel()/write()
 * 以便同时传结构化 context。
 *
 * 级别口径（本壳的关键行为，红线 §二.10）：**级别由通道名派生**——通道名里含 error 记 error 级，
 * 其余一律记 info 级（没有 debug/warn 入口）。过滤规则在 LoggerService 里：
 *  - error 级**恒写**；
 *  - 审计通道（admin_audit 前缀、以 _audit 结尾、admin_login 前缀）**恒写**，不受日志级别开关影响
 *    （L-A2 修复：后台登录留痕曾被 DEBUG=0 静默丢弃）；
 *  - 其余通道在 DEBUG=0（线上）**不写**。⇒ 想留痕的事件必须落在含 error 的名字或审计通道名下，
 *    否则线上等于没记。
 * 落盘与安全边界（均由 LoggerService 承担，此处如实记录以便排障）：
 *  文件 `runtime/log/<年Ym>/<通道>.php`，自带 php-exit 头防直链读取；通道名走
 *  `[a-zA-Z0-9_-]` 白名单，不合规则归入 error 通道（防路径穿越/审计通道伪装）；
 *  正文里的换行与制表符被剥成空格（N-07，防攻击者可控文本伪造日志行）；
 *  行内 REQUEST_URI 的 safe_token= / token= 值统一掩码（F-06，防令牌明文进日志）；
 *  超过 conf['log_max_size']（默认 10MB）自动轮转，最多留 5 份。
 * @param string $s 日志内容（内部强制 (string)：取证 exception_handler 会传 Throwable 对象，走 __toString）
 * @param string $file 通道名 = 日志文件名（不含扩展名），默认 'error'；同时决定上面的级别与是否恒写
 * @return null 恒为 NULL（底层 LoggerService::write() 无返回值，调用方不要拿它判成败/是否被过滤）
 */
function xn_log($s, $file = 'error')
{
    // 兼容壳：file 含 error 视为 error 级，其余视为 info 级（过滤语义在 LoggerService 内保持）
    $level = (false !== strpos($file, 'error')) ? \App\Service\System\LoggerService::LEVEL_ERROR : \App\Service\System\LoggerService::LEVEL_INFO;
    return \App\Service\System\LoggerService::write((string)$file, $level, (string)$s);
}

/**
 * auth_key 缺失时的**稳定**回退值（收尾轮修复审计 S-3；调用点：`core/core.php` 的 auth_key 兜底段）。
 *
 * 背景：旧回退是 `md5(__FILE__ . __DIR__ . php_uname() . uniqid(mt_rand(), true))`——**弱熵且每请求重生成**。
 * 后果：免登录 token / safe_token 指纹环 / admin_token 全部按请求漂移（表现为周期性全员掉登录），
 * 且日志里看不出原因（审计 S-3 长期悬置，因为「安装器必然写入 auth_key」使它在正常站不可达）。
 * 本函数把回退改成**跨请求稳定**，代价只有「缺 key 的站首次请求多一次写盘」：
 *   ① 持久化复用：`<cache_path>/.auth_key_fallback.php`（点文件 ⇒ `clearTmpDir()` 不删它；
 *      nginx 对 `runtime/*.php` 403；文件内只有 `return '<key>';` 无副作用，直链也读不到内容）；
 *   ② 写盘失败（只读 FS / 无 APP_PATH）：退化为**确定性派生**——熵来自 config 内容（含 db 口令），
 *      跨请求稳定且不可由公网猜测；**绝不再用 uniqid/mt_rand**；
 *   ③ 首次启用写 `auth_key_error` 通道留痕（每请求至多一条），让「缺 key」从「难诊断」变成「一眼可见」。
 * 正常站点由安装器 `xn_rand(64)` 写入 config.php ⇒ 本函数不会被调用。
 * @param array $conf 站点配置（读 cache_path/db/sitename；空数组只走确定性派生）
 * @return string 稳定的回退密钥（64 位十六进制，或 sha256 派生值）
 */
function xn_auth_key_fallback($conf = array())
{
    static $key = null;
    if (null !== $key) return $key;
    $conf = is_array($conf) ? $conf : array();

    // 目标文件：cache_path 可能是相对路径（默认 'runtime/cache/'）⇒ 相对时按 APP_PATH 解析
    $file = '';
    if (defined('APP_PATH')) {
        $dir = isset($conf['cache_path']) ? (string)$conf['cache_path'] : '';
        if ('' === $dir) $dir = 'runtime/cache/';
        if (!preg_match('#^(/|[A-Za-z]:[\\\\/])#', $dir)) $dir = rtrim(APP_PATH, '/\\') . '/' . ltrim($dir, '/\\');
        $file = rtrim($dir, '/\\') . '/.auth_key_fallback.php';
    }

    // ① 复用已持久化的 key（文件形如 `<?php return 'xxxx';`——纯返回值，include 安全）
    if ('' !== $file && is_file($file)) {
        $saved = @include $file;
        if (is_string($saved) && strlen($saved) >= 32) $key = $saved;
    }

    // ② 首次生成并尝试持久化（tmp + rename 原子落地，避免并发首访各写一半）
    if (null === $key) {
        $candidate = '';
        try {
            $candidate = bin2hex(random_bytes(32));
        } catch (\Throwable $e) {
            // 极端环境（无 CSPRNG）：退化为确定性派生，仍保证跨请求稳定
            $candidate = '';
        }
        if ('' !== $candidate && '' !== $file && is_dir(dirname($file)) && is_writable(dirname($file))) {
            $tmp = $file . '.' . getmypid() . '.tmp';
            $body = "<?php\n// NiuNiuCMS auth_key 回退密钥（自动生成）。补上 config.php 的 auth_key 后本文件可删。\nreturn " . var_export($candidate, true) . ";\n";
            if (false !== @file_put_contents($tmp, $body, LOCK_EX)) {
                @chmod($tmp, 0600);
                if (!@rename($tmp, $file)) @unlink($tmp);
            }
        }
        if ('' !== $candidate && is_file($file)) {
            $key = $candidate;
        } else {
            // ③ 落盘不可用 ⇒ 确定性派生（config 稳定 ⇒ 值稳定；含 db 口令 ⇒ 不可公网猜测）
            $key = hash('sha256', 'nncms-auth-fallback|' . __FILE__ . '|'
                . (string)($conf['db']['database'] ?? '') . '|' . (string)($conf['db']['user'] ?? '')
                . '|' . (string)($conf['db']['password'] ?? '') . '|' . (string)($conf['db']['host'] ?? '')
                . '|' . (string)($conf['sitename'] ?? ''));
        }
    }

    static $logged = false;
    if (!$logged) {
        $logged = true;
        function_exists('xn_log') and xn_log('auth_key 缺失：已启用稳定回退（' . ('' !== $file ? '持久化于 ' . $file : '确定性派生，未能落盘') . '）。请尽快在 config.php 补 64 位随机 auth_key——缺 key 会让免登录/管理令牌在重装或清缓存后失效。', 'auth_key_error');
    }
    return $key;
}
// 判断一个字符串是否在另外一个字符串里面，分隔符 ,
// 在 header 头中发送DEBUG信息
/*function t($name = '') {
	global $starttime;
	header("Time $name:".substr(microtime(1) - $starttime, 0, 7));
}*/
// 输出
// ============================================================================
// [HTTP] HTTP 函数
// ============================================================================

/**
 * 302 跳转到 $url 并**立即 exit**（`header('Location:' . $url)` 后不再执行任何代码，
 * 后面的逻辑不会跑，调用点因此都不加 return）。
 * ⚠ 紧邻的历史注释「无权限访问」是相邻函数被移走后留下的错位说明，与本函数无关：
 * 本函数只负责跳转，不做任何权限判定（权限口径是 group_access()/user_login_check()）。
 * 边界与责任划分：
 *  - 不校验、不转义 $url，也不补协议 ⇒ 站内跳转请传 url()/conf['path'] 这类系统内生成的地址；
 *    面向用户输入的「登录后回跳」走 http_referer() 的站内收敛，而不是把原始 referer 直接递进来。
 *  - 未发 `Location` 之外的状态码，PHP 默认按 302；调用前若已有输出会因 header 已发送而失效
 *    （message() 的 JSON 分支会先 ob_clean，本函数不做这层处理）。
 *  - PHP 的 header() 会拒绝含换行的头串，因此不存在响应拆分，但**开放重定向**仍要靠调用方自律。
 * @param string $url 目标地址（取证：调用点传 url('user-login')、conf['path']、route/*.php 的 $data['url']、
 *                    install/index.php 的后台入口地址）
 * @return void 永不返回（内部 exit）
 */
function http_location($url)
{
    header('Location:' . $url);
    exit;
}
/**
 * 取「回跳地址」（原注释：获取 referer）：`param('referer')` 优先，为空回落 `$_SERVER['HTTP_REFERER']`，
 * 再做两道收敛——① 命中登录/退出/注册页地址的，换成 http_url_path()（当前请求路径），防止
 * 「登录后回跳到登录页」成环；② parse_url 出 host 且与 `$_SERVER['HTTP_HOST']` 不一致的，收敛成 './'，
 * 即**只允许站内跳转**（原注释口径：不允许跳到外部，否则可能被用来做 XSS/钓鱼跳转）。
 *
 * ⚠ 与 Service 层 `App\Service\Account\UserAuthService::httpReferer()` 是**两套实现、强度不同**：
 * 那边会再剥引号/尖括号/空白并做「http(s):// + 词符」正则白名单，不过一律回退站点根；
 * 本函数看 host，并（安全审查 L-1 起）拒非 http(s) scheme 与 `//`、`/\` 开头的协议相对串，
 * 也不剥字符。前台会员登录链路请用 Service 版；本函数当前唯一调用点是后台 VodEditService。
 * 转义边界（红线 §二.4）：param() 取回的值**默认已 htmlspecialchars 一次**，
 * 本函数不再补转义、也不还原——所以它的返回串是「HTML 已安全、但直接当 Location/JS 用可能带 &amp; 实体」的形态，
 * 消费方按用途自行处理，切勿再次转义（历史上出过 `&amp;amp;` 双重转义事故）。
 * @return string 收敛后的回跳地址（可能是空串：既无 referer 参数又无 HTTP_REFERER 时）
 */
function http_referer()
{
    $referer = param('referer');
    empty($referer) and $referer = (string)_SERVER('HTTP_REFERER'); // 无 Referer 时 _SERVER() 回 NULL，PHP 8.1+ strpos/parse_url 传 NULL 弃用
    if (strpos($referer, url('user-login')) !== false || strpos($referer, url('user-logout')) !== false || strpos($referer, url('user-create')) !== false) {
        $referer = http_url_path();
    }
    // 安全过滤，只支持站内跳转，不允许跳到外部，否则可能会被 XSS
    $parse_url = parse_url($referer);
    if (isset($parse_url['host']) && $parse_url['host'] != $_SERVER['HTTP_HOST']) $referer = './';
    // 安全审查 L-1：再拒非 http(s) scheme（javascript:/data: 等无 host 串旧实现会原样通过）与协议相对 // 、/\ 形态
    if (FALSE === $parse_url || (isset($parse_url['scheme']) && !in_array(strtolower($parse_url['scheme']), array('http', 'https'), TRUE)) || preg_match('#^\s*[/\\\\]{2}#', $referer)) $referer = './';
    return $referer;
}
// $round: float round ceil floor

// 检测站点的运行级别
// ============================================================================
// [MISC] 其他工具函数
// ============================================================================

/**
 * 检测站点的运行级别（原注释口径；紧邻上方那行「$round: float round ceil floor」是相邻函数被移走后
 * 留下的错位注释，与本函数无关）。由 index.inc.php 在前台引导链上调用，命中限制即 message() 渲染提示页
 * **并终止请求**，所以调用方不需要判返回值。
 *
 * 级别语义与 core/config/config.default.php 的 runlevel 键注释同源：
 *  0 站点关闭（回 conf['runlevel_reason']）；1 仅管理员可读写；2 会员可读（游客或任何写操作被拒）；
 *  3 会员可读写（游客被拒）；4 所有人只读（非 GET 被拒）；5（出厂默认）不做任何限制（switch 里是注释掉的空分支）。
 * 两道前置豁免，顺序很重要：
 *  1) `1 == $gid`（管理员组）**在任何级别之前直接返回**，含 0 级；
 *  2) conf 级判定之前，先按 param(0)/param(1) 比对 user 路由白名单
 *     （login/create/logout/sendinitpw/resetpw/resetpw_sendcode/resetpw_complete/synlogin），
 *     命中即放行 ⇒ 关闭/限流期间游客仍能登录、注册、找回密码，避免把自己锁在门外。
 * @return void 无返回值；被限制时经 message() 输出提示页并 exit
 */
function check_runlevel()
{
    global $conf, $method, $gid;
    $rules = array(
        'user' => array('login', 'create', 'logout', 'sendinitpw', 'resetpw', 'resetpw_sendcode', 'resetpw_complete', 'synlogin')
    );
    if (1 == $gid) return;
    $param0 = param(0);
    $param1 = param(1);
    foreach ($rules as $route => $actions) {
        if ($param0 == $route && (empty($actions) || in_array($param1, $actions))) {
            return;
        }
    }
    switch ($conf['runlevel']) {
        case 0:
            message(-1, $conf['runlevel_reason']);
            break;
        case 1:
            message(-1, lang('runlevel_reson_1'));
            break;
        case 2:
            (0 == $gid || 'GET' != $method) and message(-1, lang('runlevel_reson_2'));
            break;
        case 3:
            0 == $gid and message(-1, lang('runlevel_reson_3'));
            break;
        case 4:
            'GET' != $method and message(-1, lang('runlevel_reson_4'));
            break;
            //case 5: break;
    }
}
/*
	message(0, '登录成功');
	message(1, '参数错误');
	message(-1, '数据创建失败');
0成功
1参数错误 2权限错误 3密码错误 4方法错误
-1数据创建或更新失败 -2查询数据不存在 -3数据比对为空 -4参数为空 -5获取数据为空
	code:
		< 0 全局错误，比如：系统错误：数据库丢失连接/文件不可读写
		= 0 正确
		> 0 一般业务逻辑错误，可以定位到具体控件，比如：用户名为空/密码为空
*/
function message($code, $message, $extra = array())
{
    global $json, $header, $conf;
    $arr = $extra;
    $arr['code'] = $code;
    $arr['message'] = $message;
    $arr['type'] = is_int($code) ? ($code == 0 ? 'success' : ($code < 0 ? 'error' : 'warning')) : 'info';
    // jump() 自动跳转脚本：仅白名单提取 jump() 生成的 setTimeout(window.location) 结构；
    // url 段禁 `<`（防 </script> 逃逸 XSS）与 `'`/`\`（防 JS 转义构造 /\/\/、\x2f 等绕过站内校验）
    $arr['jump_script'] = '';
    if (is_string($message) && preg_match('#<script>(setTimeout\(function\(\) \{window\.location=\'([^<\'\\\\]*)\'\}, \d+\));</script>#s', $message, $_m)) {
        $_jurl = $_m[2];
        $_jurl_ok = '' !== $_jurl
            && '//' !== substr($_jurl, 0, 2)
            && 0 !== stripos($_jurl, 'javascript:')
            && 0 !== stripos($_jurl, 'data:')
            && !preg_match('#^https?://#i', $_jurl);
        if ($_jurl_ok) {
            $arr['jump_script'] = '<script>' . $_m[1] . '</script>';
            // 先剥 script 标签及内容（strip_tags 不保证剥离 script 内容，7.4/8.5 实测保留），再剥其余标签（a）：
            // jump 场景显示纯文本（自动跳转已由 jump_script 承担，避免转义后显示 HTML 源码）
            $_msg_noscript = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $message);
            $arr['message'] = trim(strip_tags($_msg_noscript));
        }
    }
    $header['title'] = $conf['sitename'];
    // 防止 message 本身出现错误死循环
    static $called = FALSE;
    $called ? exit(xn_json_encode($arr)) : $called = TRUE;

    // JSON 响应：$json 显式开启，或后台 AJAX 请求（jQuery/$.xpost 默认带 X-Requested-With）
    // 仅在后台路由开启此行为（前台 jQuery AJAX 提交仍需 HTML 提示页，否则误伤登录/注册等）
    $_is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower(trim($_SERVER['HTTP_X_REQUESTED_WITH'])) === 'xmlhttprequest';
    // 后台请求判定：IN_ADMIN 常量（admin.php 入口定义，替代 admin_path 路径判断）；
    // 保留旧路径兜底（core/admin 路径与 admin/index 历史入口）
    $_is_admin = (defined('IN_ADMIN') && IN_ADMIN) || (isset($_SERVER['SCRIPT_NAME']) && (false !== strpos($_SERVER['SCRIPT_NAME'], 'core/admin') || false !== strpos($_SERVER['SCRIPT_NAME'], 'admin/index')));
    if ($json || ($_is_ajax && $_is_admin)) {
        if (ob_get_level()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo xn_json_encode($arr);
        exit;
    }

    // CLI 模式
    if (IN_CMD) {
        if (is_array($message) || is_object($message)) {
            print_r($message);
        } else {
            echo $message;
        }
        exit;
    }

    // 浏览器请求：渲染提示页面
    if (defined('MESSAGE_HTM_PATH')) {
        include _include(MESSAGE_HTM_PATH);
    } else {
        // 上线前审计修复（L-01）：提示页模板自 static/html/ 迁至 core/html/——
        // static/ 下 .htm 无 nginx 拦截被直出 PHP 源码（实测 200 泄露），core/ 目录整段 deny。
        include _include(APP_PATH . "core/html/message.htm");
    }
    exit;
}
/**
 * 上锁（原注释「上锁」）：在 `conf['cache_path']` 下写 `lock_<锁名>.lock` 文件占位，
 * 用「锁文件是否存在 + mtime 是否过期」实现跨请求的互斥提示（index.inc.php 的引导期任务、
 * core/cache/cache.php 的缓存重建锁都靠它）。
 *
 * ⚠ 语义边界，别当严格互斥用：
 *  - **不是 flock/原子创建**，是「先 is_file 判断再 file_put_contents」的乐观锁，
 *    两个并发请求同时判空都可能写成功 ⇒ 只挡顺序重复执行，不保证并发唯一；
 *  - 锁「超时自动可抢」：已存在但 mtime 距今超过 $life 秒时先删再写（视为上个持有者崩了）；
 *  - 锁名走白名单：上线前安全审计修复 **F-16**——$lockname 直拼路径，含 ../ 时可穿越出
 *    runtime/cache 去写/删任意 lock_*.lock，现统一剥掉非 `[A-Za-z0-9_-]` 的字符
 *    （当前全站调用方传的都是常量名，这道闸是防未来误用）；
 *  - 时间基准取全局 $time（请求开始时的容器时间，§二.5），不是每次现取 time()。
 * @param string $lockname 锁名（拼进文件名，先过上面的字符白名单；留空会得到 lock_.lock 这种共享锁）
 * @param int $life 锁的存活秒数：超过即认定持有者失效并抢占，默认 10（index.inc.php 传 30）
 * @return int|false 成功 = file_put_contents 写入的字节数（truthy，调用方按真假判）；
 *                   锁被占用未过期 = FALSE；写文件失败（目录不可写等）= FALSE
 */
function xn_lock_start($lockname = '', $life = 10)
{
    global $conf, $time;
    // 上线前安全审计修复（F-16）：锁名白名单——$lockname 直拼缓存路径，
    // 含 ../ 时可穿越出 runtime/cache 写/删任意 lock_*.lock（当前全站调用方均为常量名，防未来误用）。
    $lockname = preg_replace('#[^A-Za-z0-9_-]#', '', (string)$lockname);
    $lockfile = $conf['cache_path'] . 'lock_' . $lockname . '.lock';
    if (is_file($lockfile)) {
        // 大于 $life 秒，删除锁
        if ($time - filemtime($lockfile) > $life) {
            xn_unlink($lockfile);
        } else {
            // 锁存在，上锁失败。
            return FALSE;
        }
    }
    $r = file_put_contents($lockfile, $time, LOCK_EX);
    return $r;
}
/**
 * 删除锁（原注释「删除锁」）：按与 xn_lock_start() 完全相同的拼名规则（`cache_path` + `lock_` 前缀 +
 * `.lock` 后缀、同一套 F-16 字符白名单）算出锁文件并 xn_unlink()。
 * 只按锁名删，**不校验锁是不是自己上的**，也不看过期时间 ⇒ 调用方要保证 start/end 成对且锁名一致，
 * 否则可能把别的请求刚拿到的锁删掉（配合 xn_lock_start 的乐观锁语义，属可接受的松耦合用法）。
 * 文件不存在时 xn_unlink() 直接返回 FALSE，不报错，所以重复调用安全。
 * @param string $lockname 锁名，必须与上锁时同一个串（非法字符同样被剥除）
 * @return void 无返回值（删除成败不外抛，调用方无法据此判断）
 */
function xn_lock_end($lockname = '')
{
    global $conf;
    // 与 xn_lock_start 同口径白名单（F-16）
    $lockname = preg_replace('#[^A-Za-z0-9_-]#', '', (string)$lockname);
    $lockfile = $conf['cache_path'] . 'lock_' . $lockname . '.lock';
    xn_unlink($lockfile);
}
include CORE_PATH . 'lib/html_safe.php';
/**
 * 前台访问 view 目录（模板资源目录 conf['view_url']，出厂 template/）的 URL 前缀，
 * 「支持分离」= 模板目录可以挂到独立地址/子目录上，因此前缀要跟着伪静态形态变
 * （原注释：前台访问 view 目录下文件路径/支持分离）。
 * 规则：conf['url_rewrite_on'] 大于 1（开了伪静态）才前置 conf['path']，否则直接返回 view_url；
 * 结果按 static 键 view_path 缓存到请求结束，同一请求内只算一次。
 * ⚠ 值取自 _SERVER('conf') 门面（§二.5），不读物理 config.php 的副本。
 * ⚠ 本函数与 admin_view_path()/admin_attach_path() 在当前仓库里**都没有调用点**
 * （conf['attach_on'] 的注释仍引用它们），属遗留的前台路径 helper；改动前先确认是否还留作插件用。
 * @return string 模板资源 URL 前缀（如 `template/` 或 `<站点路径>template/`）；不保证结尾带斜杠，
 *                 取决于 conf['view_url'] 自身的写法
 */
function view_path()
{
    static $path = array();
    if (isset($path['view_path'])) return $path['view_path'];
    $conf = _SERVER('conf');
    $conf_path = $conf['url_rewrite_on'] > 1 ? $conf['path'] : '';
    $path['view_path'] = $conf_path . $conf['view_url'];
    return $path['view_path'];
}
/**
 * 后台访问 view 目录（模板资源目录）的相对路径前缀（原注释「后台访问 view 目录下文件路径/支持分离」）：
 * 后台页面在 `.../core/admin/xxx` 层级下，所以 conf['view_url'] 恰好是出厂值 `view/` 形态时要前置 `../`
 * 抬一级，否则（站点装在子目录、或 view_url 已是绝对/改写过的值）原样返回。
 * 与前台 view_path() 的差别就在这里：前台不看目录层级，后台靠「../」补偿相对深度；
 * 结果同样按 static 键缓存到请求结束。
 * ⚠ 上方那行「附件路径/支持分离 $attach_on 为传入标识，默认不传入读取 $conf['attach_on']」是
 * 相邻的附件路径 helper 被移走后留下的错位注释（本函数不读 attach_on，也没有 $attach_on 形参）；
 * attach_on 的语义现在由下面的 admin_attach_path() 承担。
 * ⚠ 本函数在当前仓库无调用点（与 view_path()/admin_attach_path() 同属遗留 helper）。
 * @return string 后台页面可用的 view 目录相对路径（如 `../view/` 或原样的 conf['view_url']）
 */
function admin_view_path()
{
    static $path = array();
    if (isset($path['admin_view_path'])) return $path['admin_view_path'];
    $conf = _SERVER('conf');
    $path['admin_view_path'] = 'view/' == $conf['view_url'] ? '../' . $conf['view_url'] : $conf['view_url'];
    return $path['admin_view_path'];
}
/**
 * 后台页面引用附件（upload 目录）时用的相对路径前缀：
 *  - conf['attach_on'] 为 0（附件未与站点分离）时返回「回一级」的前缀——伪静态未开到 2 以上给 `../`，
 *    已开伪静态（URL 少一级目录）给不带尾斜杠的 `..`，两种形态对应不同的目录深度；
 *  - attach_on 非 0（附件/云存储分离，见 conf 注释）时返回空串 ⇒ 附件地址由调用方用绝对 URL 拼，
 *    本前缀不再参与。
 * 结果按 static 键缓存到请求结束（注意缓存前先置空再算，所以异常路径也会固化一个空串）。
 * ⚠ 紧邻的两行原注释「后台处理头像或主题缩略图、自定义图标」「后台处理内容图、附件路径」是相邻
 * helper（头像/缩略图/内容图取路径）被移走后留下的错位说明，本函数只做上面这一件事。
 * ⚠ 本函数与 view_path()/admin_view_path() 在当前仓库均无调用点；conf['attach_on'] 的说明仍引用它，
 * 现存同类能力请走 StorageService/file_path() 一侧。
 * @return string 相对路径前缀：`../`、`..` 或空串（附件分离/未命中分支）
 */
function admin_attach_path()
{
    global $conf;
    static $cache = array();
    $key = 'admin_attach_path';
    if (isset($cache[$key])) return $cache[$key];
    $cache[$key] = '';
    // 未分离图片
    if (0 == $conf['attach_on']) {
        // 伪静态 1 追加 ../
        if ($conf['url_rewrite_on'] < 2) {
            $cache[$key] = '../';
        } else {
            $cache[$key] = '..';
        }
    }
    return $cache[$key];
}
/*
 * @param $uid 当前用户UID
 * @param $token 获取的token
 * @param int $safe_key 验证当前页token 为空不验证
 * @param int $life token 生命期
 * @return bool|mixed|string 返回 token 验证成功 / FALSE 验证失败
 */
// 格式化数字 1k
//---------------表单安全过滤---------------
/**
 * 安全获取表单参数（支持多维数组）
 * @param string|int $key 参数名
 * @param mixed $defval 默认值
 * @param array $filter 需要按字符串处理的key数组，如 array('name','message')
 * @param bool $htmlspecialchars 是否进行HTML转义
 * @param bool $addslashes 是否转义引号
 * @return array|string|int 过滤后的参数值
 */
/*
 * 专门处理表单多维数组安全过滤 指定最终级一维数组key为字符串安全处理
    $filter 为需要按照字符串处理的key数组 array('key1','key2')
    如需按照int型处理时 $filter 数组为空或省略
    $filter = array('name','message','brief');
	bull_param(1, array(), $filter);
    bull_param('warm_up', array(), array('name','message','brief'));
*/
//---------------表单安全过滤结束---------------
/*
 * @param $str 转换字符串
 * @param string $charset 转换编码
 * @param string $original 字符串原始编码
 * @return string
 */
/**
 * 检查插件是否已安装并启用
 * 支持 plugin/ 和 addons/ 两种目录结构
 * @param string $dir 插件目录名
 * @param string $file 可选，读取插件内指定文件内容
 * @param bool $return 返回文件内容时是否进行 HTML 转义
 * @return bool|string 插件启用状态或文件内容
 */
/**
 * 获取插件信息（从 conf.json 读取）
 * @param string $dir 插件目录名
 * @return array|false 插件配置数组，失败返回 FALSE
 */
/**
 * UA 设备探测，返回设备码（原注释口径：**0=pc、1=wechat、2=pad、3=mobile**）。
 * 判定按优先级短路：UA 含 MicroMessenger 记 1；含 pad/Pad 记 2；
 * 否则命中 `HTTP_X_WAP_PROFILE`、`HTTP_VIA` 含 wap、或 UA 含 phone/mobile/ipod 记 3；都不沾记 0。
 * 结果以 md5(UA) 为键做请求内 static 缓存，同请求重复调用不再解析 UA。
 * 消费方（取证：core/model/view.php 的 view_load() 拿它当 $detect，交给 TemplateService 给模板文件名
 * 加设备前缀）⇒ 返回的是**设备码整数**，不是布尔，别写成 `if (get_device())`：PC 恰好是 0 会被判假。
 * ⚠ 只做 UA 形态猜测，无验证码/WAP 网关判定；伪造 UA 可随意改变模板选取分支，属展示层而非安全边界。
 * 上方两段插件相关 docblock（检查插件是否启用 / 读 conf.json）是相邻函数迁走后的遗留，与本函数无关。
 * @return int 0=PC、1=微信内置浏览器、2=pad、3=手机
 */
function get_device()
{
    $agent = _SERVER('HTTP_USER_AGENT');
    static $cache = array();
    $md5 = md5($agent);
    if (isset($cache[$md5])) return $cache[$md5];
    if (str_contains($agent, 'MicroMessenger')) {
        $cache[$md5] = 1; // 微信
    } elseif (str_contains($agent, 'pad') || str_contains($agent, 'Pad')) {
        $cache[$md5] = 2; // pad
    } elseif (isset($_SERVER['HTTP_X_WAP_PROFILE']) || (isset($_SERVER['HTTP_VIA']) && stristr($_SERVER['HTTP_VIA'], "wap") || strpos(strtolower($agent), 'phone') !== false || strpos(strtolower($agent), 'mobile') !== false || str_contains($agent, 'ipod'))) {
        $cache[$md5] = 3; // 手机
    } else {
        $cache[$md5] = 0;
    }
    return $cache[$md5];
}
/**
 * 生成唯一身份 ID（原注释「唯一身份 ID」）：16 位小写十六进制串，
 * 取 random_bytes(8) 经 bin2hex（审计修复：优先 CSPRNG，不再用 mt_rand/uniqid 拼时间）。
 * 版本分支里的 `uniqid(substr(md5(...)))` 回退只在没有 random_bytes 的低版本 PHP 才走到，
 * 本站 PHP 8.5 **恒不可达**，且那条回退是「时间+弱随机前缀」，不具 CSPRNG 强度——别指望降级形态。
 *
 * 语义边界（取证调用点）：CategoryManageService 用它生成分类 alias 并**随后校验保留字与重复**、
 * ApiExecutionService 用它给采集入库的 vod 生成 alias ⇒ 本函数**不保证全局唯一**，
 * 无库内查重、无冲突重试，唯一性来自 CSPRNG 的碰撞概率；有唯一约束的字段仍要自己判重。
 * 输出字符集只有 0-9a-f，可直接进 URL/标识符，无需再转义（但它不是令牌，别拿来做凭据——
 * 凭据请用 xn_rand()/xn_key() 那条链路并配 auth_key 口径）。
 * ⚠ 紧邻的两行原注释「random string, no number」「html 换行转换为 \r\n」是相邻 helper 迁走/移除后
 * 留下的错位说明：无数字随机串的实现其实在 core/func/file.php 的 xn_rand()，本函数结果**含数字**；
 * 换行转 \r\n 的函数全仓已不存在。
 * @return string 16 位十六进制唯一串
 */
function uniq_id()
{
    // 优先 CSPRNG（审计修复）；低版本回退 uniqid
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes(8));
    }
    return uniqid(substr(md5(microtime(true) . mt_rand(1000, 9999)), 8, 8));
}
// 生成订单号 14位
// 生成订单号 16位
// 当前年的天数
/**
 * HTTPS请求（支持GET/POST，自动选择curl或stream）
 * @param string $url 提交地址
 * @param string $post POST数组 / 空为GET获取数据 / 'GET'获取连续跳转最终URL
 * @param string $cookie cookie
 * @param int $timeout 超时
 * @param int $ms 设为1是毫秒超时
 * @param int $maxBytes 响应体上限（字节，0=不限）：超限在**传输中**中止（进度回调返 1 → curl 中止 →
 *                      本函数返回 FALSE），不会先把整包拉进内存。
 *                      ⚠ 仅 curl 路径生效：无 curl 扩展时走 stream 回退（https_post/http_get），
 *                      其读取长度在 core/func/http.php 内**硬编码 4096000（≈4MB）**，既不随本参数
 *                      放大也不缩小（复核更正：旧注释写「不设限」是错的，实际是固定 4MB 静默截断——
 *                      截断后的 JSON 会解码失败，调用方只能看到「没数据」）。采集页/列表类调用点建议给 8MB。
 * @return mixed 返回数据（FALSE=失败或超限）
 */
function https_request($url, $post = '', $cookie = '', $timeout = 30, $ms = 0, $maxBytes = 0)
{
    if (empty($url)) return FALSE;
    is_array($post) and $post = http_build_query($post);
    // 没有安装curl 使用http的形式，支持post
    if (!extension_loaded('curl')) {
        //throw new Exception('server not install CURL');
        if ($post) {
            return https_post($url, $post, $cookie, $timeout);
        } else {
            return http_get($url, $cookie, $timeout);
        }
    }
    is_array($cookie) and $cookie = http_build_query($cookie);
    $curl = _curl_setup($url);
    if (FALSE === $curl) return FALSE; // SSRF 防护：出站 URL 校验失败
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_MAXREDIRS, 5);
    // SSRF 防护：禁自动跟随，改手动逐跳校验（outbound_curl_exec）
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($curl, CURLOPT_MAXREDIRS, 0);
    // 传输中体积上限（审查修复）：进度回调超限返回 1 即中止传输（CURLE_ABORTED_BY_CALLBACK），
    // RETURNTRANSFER 仍正常收流，outbound_curl_exec 的每跳重定向同样受此回调约束。
    if ($maxBytes > 0) {
        curl_setopt($curl, CURLOPT_NOPROGRESS, false);
        curl_setopt($curl, CURLOPT_PROGRESSFUNCTION, function ($ch, $dlTotal, $dlNow, $ulTotal, $ulNow) use ($maxBytes) {
            return $dlNow > $maxBytes ? 1 : 0;
        });
    }
    $ua1 = 'Mozilla/5.0 (iPhone; CPU iPhone OS 13_2_3 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.0.3 Mobile/15E148 Safari/604.1';
    $ua = empty($_SERVER["HTTP_USER_AGENT"]) ? $ua1 : $_SERVER["HTTP_USER_AGENT"];
    curl_setopt($curl, CURLOPT_USERAGENT, $ua);
    $header = array('Content-type: application/x-www-form-urlencoded;charset=UTF-8', 'X-Requested-With: XMLHttpRequest');
    $cookie and $header[] = "Cookie: $cookie";
    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
    if ($post) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_AUTOREFERER, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $post);
    }
    if ($ms) {
        curl_setopt($curl, CURLOPT_NOSIGNAL, true);
        curl_setopt($curl, CURLOPT_TIMEOUT_MS, intval($timeout));
    } else {
        curl_setopt($curl, CURLOPT_TIMEOUT, intval($timeout));
    }
    // 返回执行结果（手动跟随重定向，每跳 SSRF 校验）
    $output = outbound_curl_exec($curl, $url, intval($timeout), 5, !empty($ms));   // $ms=1 时保留调用方的 TIMEOUT_MS（复核修正）
    // 有效URL，输出URL非URL页面内容 CURLOPT_RETURNTRANSFER 必须为false
    'GET' == $post and $output = curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
    // curl_close() is a no-op since PHP 8.0 and deprecated since 8.5
    // The handle is automatically closed when it goes out of scope.
    return $output;
}
/**
 * 递归扫描目录并**直接 echo** 可疑脚本文件（原注释「搜索目录下的文件 比对文件后缀」）：
 * scandir 逐项走，`.`/`..` 跳过，子目录递归自身，文件的扩展名（file_ext() 取、转小写）命中
 * php / asp / jsp / cgi / exe / dll 之一就打印「异常文件：+路径」。用途是手工排查可疑脚本与
 * 遗留可执行文件（红线 §二.7/§二.10 的「可写目录禁执行 PHP」自检可以借它一把）。
 *
 * ⚠ 现状与边界，接手前必读：
 *  - **无任何调用点**（只有自身递归），属手工/调试工具；不要把它接进 Web 请求路径：
 *    输出没有转义、路径是服务器绝对路径（泄露敏感信息，§二.10），大目录还会跑满执行时间；
 *  - 只比扩展名，不看内容/MIME，判不出的变形（如 phtml、phar、大小写混排以外的绕过名）不算检出；
 *  - 不跟随软链判定、不做权限与体积统计，也不返回任何结果（无 return，靠 echo 呈现）。
 * ⚠ 紧邻上一行「计算字串宽度：剧中对齐(字体大小/字串内容/字体链接/背景宽度/倍数)」是相邻函数
 * 被移除后留下的错位注释（全仓已无该宽度计算 helper），与本函数无关。
 * @param string $path 起始目录路径（取证：调用点传 APP_PATH 一类的绝对目录串；非目录时函数静默返回）
 * @return void 无返回值（检出项直接 echo）
 */
function search_directory($path)
{
    if (is_dir($path)) {
        $paths = scandir($path);
        foreach ($paths as $val) {
            $sub_path = $path . '/' . $val;
            if ('.' == $val || '..' == $val) {
                continue;
            } else if (is_dir($sub_path)) {
                //echo '目录名:' . $val . '<br/>';
                search_directory($sub_path);
            } else {
                //echo ' 最底层文件: ' . $path . '/' . $val . ' <hr/>';
                $ext = strtolower(file_ext($sub_path));
                if (in_array($ext, array('php', 'asp', 'jsp', 'cgi', 'exe', 'dll'), TRUE)) {
                    echo '异常文件：' . $sub_path . ' <hr/>';
                }
            }
        }
    }
}
// 一维数组转字符串 $sign待签名字符串 $url为urlencode转码GET参数字符串
// Array to xml array('appid' => 'appid', 'code' => 'success')
// Xml to array
$g_dir_file = FALSE;
// 无 Notice 方式的获取超级全局变量中的 key
//字符编码转换
//蜘蛛标识

// ==== Route Helpers (extracted from route/vod.php + route/play.php) ====

/**
 * Ensure video has tags (auto-generate from metadata) and fetch related videos.
 * Extracted from duplicated logic in route/vod.php and route/play.php .
 */
// ============================================================================
// [ROUTE] 路由辅助函数
// ============================================================================


/**
 * Shared output logic for list/category pages (art and vod).
 * Extracted from duplicated logic in route/list.php and route/category.php .
 * 
 * @param string $type 'art' or 'vod'
 * @param array $extra Keys: view (override template), adlist, extra_json
 */
function route_list_page($type, $cid, $cate, $num, $page, $pagesize, $page_url, $items, $conf, $extra = array()) {
    // 修复：模板头部 {if:(group_access($gid, 'intoadmin'))}（后台入口）在本函数作用域内读不到 $gid/$admin_entry——
    // 分类/列表页管理员看不到「后台管理」入口，且每次渲染刷 Undefined variable $gid 警告
    global $json, $seo, $api_json, $cate_nav, $path_tpl, $nncms, $search_url, $user_logout, $pku_url, $nav_active, $nav_route, $nav_home, $nav_pku, $uid, $gid, $user, $admin_entry;
    $_SESSION['cid'] = $cid;
    $_SESSION['num'] = $num;
    $_SESSION['page'] = $page;
    $_SESSION['pagesize'] = $pagesize;
    $_SESSION['page_url'] = $page_url;
    
    $seo_key = ($type === 'art') ? 'art_list' : 'list';
    $items_key = ($type === 'art') ? 'artlist' : 'videolist';
    $view = array_value($extra, 'view');
    if (!$view) {
        $view = ($type === 'art') ? 'art_list' : 'list';
    }
    $adlist = array_value($extra, 'adlist', array());
    $extra_json = array_value($extra, 'extra_json', array());
    
    \App\Service\Front\SeoService::apply($conf['seo'][$seo_key], [
        'name' => $cate['name'],
        'page' => $page,
        'sitename' => $conf['sitename'],
    ], [
        // canonical 取当前页规范 URL；第 2 页起 noindex,follow（筛选/分页参数不产生重复索引）
        'url' => \App\Service\Front\SeoService::currentUrl(),
        'robots' => intval($page) > 1 ? 'noindex,follow' : '',
    ]);
    
    // 将 items 暴露为模板变量（$videolist 或 $artlist）
    $$items_key = $items;
    // 分页 + 频道数据（模板需要）
    $index_page = index_page($page_url, $num, $page, $pagesize);
    $page_list = page_array($page_url, $num, $page, $pagesize);
    $arrlistall = array_value($extra, 'arrlistall', array());
    $sticky_list = array_value($extra, 'sticky_list', array());
    $sticky_list = slide_items_decorate(is_array($sticky_list) ? array_values($sticky_list) : array()); // 0 起索引 + slide_img/slide_type
    $type_urls = array_value($extra, 'type_urls', array());
    $sonlist = array_value($extra, 'sonlist', array());
    $req_area = array_value($extra, 'req_area', '');
    $req_lang = array_value($extra, 'req_lang', '');
    $req_year = array_value($extra, 'req_year', '');
    $cup = array_value($extra, 'cup', $cate['cup'] ?? 0);
    $pageurl = str_replace('{page}', $page, $page_url);
    $adlist = $adlist;

    if ($json) {
        $api_json['cid'] = $cid;
        $api_json['cate_name'] = $cate['name'];
        $api_json['cate_url'] = $cate['url'];
        $api_json['seo'] = $seo;
        $api_json['num'] = $num;
        $api_json['page'] = $page;
        $api_json['pagesize'] = $pagesize;
        $api_json['page_url'] = $page_url;
        foreach ($extra_json as $k => $v) {
            $api_json[$k] = $v;
        }
        $api_json[$items_key] = $items;
        $api_json['adlist'] = $adlist;
        $conf['api_on'] ? message(0, $api_json) : (http_response_code(403) and message(-1, lang('closed')));
    } else {
        include _file(view_load($view, $cid));
    }
}

/**
 * 页面级缓存-起点：命中直接输出并返回 TRUE，未命中开启输出缓冲返回 FALSE
 * 仅 DEBUG=0 线上模式启用；双层防御 !== null && !== false，避免缓存驱动异常导致空白页
 * @param string $cacheKey 缓存键
 * @param callable|null $onHit 命中输出前的回调（如设置响应头）
 * @return bool TRUE=命中已输出
 */
// ============================================================================
// [CACHE] 页面缓存函数
// ============================================================================

/**
 * page_cache 键成分归一（上线审核轮 P1-5）：
 * 旧键=md5(原始 REQUEST_URI)——扫描器对真实路由追加随机参数即造无界新键（每键一次完整渲染+回填）。
 * 归一只保留路由层真实消费的查询段：
 *   ① 裸 token 段（本站路由主形态 ?user-login.html / ?vodplay-…html；路由不命中根本到不了缓存调用，键空间天然有界）；
 *   ② 数字命名段（?0=…&1=… param 式路由）；
 *   ③ 命名参数白名单（分页/搜索词）。
 * ⚠ 未来任何影响渲染结果的具名 query 参数必须同步白名单，否则不同参数页面会共享同一缓存。
 */
function page_cache_uri_key($uri)
{
    // 安全审查 U-3（2026-10-24）收紧：键只含路由真实消费的成分，其余一律丢弃——
    //   ① 裸 token：只在路径无路由段（以 / 结尾或 *.php 入口）时保留**第一个**（?vod-1.html 形态的路由本体）；
    //      其后的裸 token 经 parse_str 只会变成无人读取的具名空参数，不入键；
    //   ② 数字命名段 0..63（param(N) 式路由；更大的下标无路由读取）；
    //   ③ 命名白名单：page/p（intval）、cid（intval）、keyword（>64 字节摘要化）。
    //      wd/q/k/jsoncallback 全仓无读取点，删除；json 已由调用方以 _j 档位入键，删除。
    // ⚠ 未来任何影响渲染结果的具名 query 参数必须同步白名单，否则不同参数页面会共享同一缓存。
    $uri = (string)$uri;
    $q = strpos($uri, '?');
    if (FALSE === $q) return strlen($uri) > 256 ? md5($uri) : $uri;
    $path = substr($uri, 0, $q);
    $query = substr($uri, $q + 1);
    if (strlen($path) > 256) $path = md5($path);
    if ('' === $query) return $path;
    $last = (string)substr($path, (int)strrpos('/' . $path, '/'));
    $routeInQuery = ('' === $last || '.php' === substr($last, -4));
    $keep = array();
    $bareTaken = FALSE;
    foreach (explode('&', $query) as $kv) {
        if ('' === $kv) continue;
        $eq = strpos($kv, '=');
        $k = FALSE === $eq ? $kv : substr($kv, 0, $eq);
        $v = FALSE === $eq ? NULL : substr($kv, $eq + 1);
        if (preg_match('/^\d{1,2}$/', $k) && (int)$k < 64) {
            $keep[] = $k . '=' . (NULL === $v ? '' : (strlen($v) > 64 ? substr(md5($v), 0, 16) : $v));
            continue;
        }
        if (NULL === $v) {
            if ($routeInQuery && !$bareTaken) {
                $bareTaken = TRUE;
                $keep[] = strlen($kv) > 128 ? md5($kv) : $kv;
            }
            continue;
        }
        $lk = strtolower($k);
        if ('page' === $lk || 'p' === $lk || 'cid' === $lk) {
            $keep[] = $lk . '=' . intval($v);
        } elseif ('keyword' === $lk) {
            $keep[] = 'keyword=' . (strlen($v) > 64 ? md5($v) : $v);
        }
    }
    $key = $path . (array() === $keep ? '' : '?' . implode('&', $keep));
    return strlen($key) > 256 ? ($path . '?' . md5($key)) : $key;
}

/**
 * 页缓存载荷压缩/还原（第六轮复审修复 R6-03；编码格式 v2）
 *
 * 背景：page_cache 直接缓存**整页 HTML**，实测首页单条 786KB——写入 APCu（默认 shm 32MB）
 * 十几个 URI 变体即撑满（写失败静默），Redis 侧无 maxmemory 策略；MySQL 层列为 mediumtext，
 * 且 cache_mysql 以 JSON 存值——载荷必须是纯 ASCII，二进制压缩流不能直接落库。
 * 方案（v2）：>4KB 的载荷用 zlib_encode(ZLIB_ENCODING_DEFLATE) 压缩后转十六进制（纯 ASCII），
 * 前缀 "\x00ZH" 标识压缩态；未达阈值/压缩不划算/无 zlib 时原样返回。
 * v1（"\x00GZ" 前缀）曾用「Base64 包装 + raw deflate」，这种「解码后再解压」的组合正是主机杀毒
 * 启发式（Imunify360 / ai-bolit / ClamAV 启发式等）给混淆代码打分的典型特征，故改为十六进制 + zlib_decode。
 * 体积约为原文的 1/5～1/8（hex 比 Base64 大 50%，可接受）。
 * 兼容：无前缀的存量明文条目原样透传；v1 "\x00GZ" 条目与任何解不开的 "\x00" 前缀条目
 * 由 page_cache_unpack 返回 NULL ⇒ page_cache_start 视为未命中、删除后重新渲染回填（不报错、不输出乱码）。
 */
function page_cache_pack($out)
{
    if (!is_string($out) || strlen($out) <= 4096 || !function_exists('zlib_encode')) return $out;
    $z = @zlib_encode($out, ZLIB_ENCODING_DEFLATE, 6);
    if (is_string($z) && '' !== $z && 2 * strlen($z) + 3 < strlen($out)) {
        return "\x00ZH" . bin2hex($z);
    }
    return $out;
}

/**
 * 页缓存载荷还原（page_cache_pack 的逆操作，格式口径见其头部注释）
 * - 非字符串 / 不以 "\x00" 开头（明文整页，含 v1 之前的存量条目）⇒ 原样返回；
 * - "\x00ZH" + 合法十六进制 + zlib 流可解 ⇒ 返回整页 HTML；
 * - 其余 "\x00" 前缀条目（v1 "\x00GZ" 旧格式、截断/损坏、无 zlib）⇒ 返回 NULL，调用方按未命中处理。
 * @param mixed $cached 缓存取回值
 * @return mixed 还原后的整页 HTML；NULL=不可用（视为未命中）
 */
function page_cache_unpack($cached)
{
    if (!is_string($cached) || '' === $cached || "\x00" !== $cached[0]) return $cached;
    if (0 === strncmp($cached, "\x00ZH", 3) && function_exists('zlib_decode')) {
        $hex = substr($cached, 3);
        if ('' !== $hex && 0 === strlen($hex) % 2 && ctype_xdigit($hex)) {
            $bin = hex2bin($hex);
            $out = is_string($bin) ? @zlib_decode($bin) : FALSE;
            if (is_string($out) && '' !== $out) return $out;
        }
    }
    return NULL;
}

/**
 * 内容版本令牌（内容写失效专项 R6-04）
 *
 * 背景：页缓存同时落 nncms_cache 表 / Redis db1 / **各 FPM worker 独立 APCu 段**，
 * 内容写后无法逐键删除其他 worker 的 APCu 条目（见 CLAUDE.md 永久结论「页缓存多层独立」）。
 * 解法：把版本号并进页缓存键——写入侧推进版本，所有 worker 随即查不到旧键（旧键按 TTL 自然过期），
 * 从而跨 worker 即时失效，无需枚举删除。
 *
 * 版本必须从**跨进程共享层**读取（redis/mysql），不能读 static/apcu——否则别的 worker 的
 * 推进对本 worker 不可见。请求内静态 memo，避免重复读取。
 */
function page_cache_version()
{
    static $ver = NULL;
    if (NULL !== $ver) return $ver;
    $raw = NULL;
    try {
        if (function_exists('cache_shared_get')) $raw = cache_shared_get('page_content_ver');
    } catch (\Throwable $e) { $raw = NULL; }
    $ver = (is_scalar($raw) && '' !== $raw) ? (string)$raw : '0';
    return $ver;
}

/** 页缓存键叠加内容版本 + 站群站点（start/end 必须同口径） */
function page_cache_vkey($cacheKey)
{
    return $cacheKey . page_cache_site_suffix() . '_cv' . page_cache_version();
}

/**
 * 站群修复：整页缓存此前不区分访问域名——各路由的键只含 URI/gid/伪静态档位，站群站点的站点名、模板（path）
 * 与主站或其他同档位站点不同，却共用同一份缓存，先访问的站点内容会被回放给其他域名 1800s。
 * 只对 domain.php 里登记过的站点追加后缀（键空间有界，Host 头随意伪造不会造出新键）；主站后缀为空，原有缓存不失效。
 */
function page_cache_site_suffix()
{
    static $suffix = NULL;
    if (NULL !== $suffix) return $suffix;
    $hosts = $GLOBALS['hosts'] ?? '';
    $domain = $GLOBALS['domain'] ?? array();
    $suffix = ('' !== (string)$hosts && is_array($domain) && isset($domain['site'][$hosts])) ? '_s' . substr(md5((string)$hosts), 0, 12) : '';
    return $suffix;
}

/**
 * 推进内容版本 → 全站页缓存即时失效。
 * 由内容写入路径调用（DbTableRepository 内容表白名单 / 分类缓存清理等）。
 * 节流：同进程 2 秒内短路（避免采集逐行写入反复推进），跨进程再以共享时间戳兜底。
 * @return bool 是否真正推进
 */
function page_cache_bump($force = FALSE)
{
    static $localLast = 0;
    $now = time();
    if (!$force && $now - $localLast < 2) return FALSE;   // 同请求/同 CLI 进程内直接短路
    $localLast = $now;
    try {
        if (!$force) {
            $last = function_exists('cache_shared_get') ? cache_shared_get('page_content_bump_ts') : NULL;
            if (is_numeric($last) && ($now - (int)$last) < 2) return FALSE; // 跨进程节流
        }
        cache_set('page_content_bump_ts', $now, 120);
        cache_set('page_content_ver', $now . '-' . mt_rand(100000, 999999), 0);
        // 返回值仅代表「已尝试推进」：cache_set 是各层 AND，CLI 无 APCu 时会返回 false，
        // 但共享层（Redis）已写入即可跨 worker 生效，故不以其结果判断成败。
        return TRUE;
    } catch (\Throwable $e) {
        return FALSE;
    }
}

/**
 * 页面级缓存-起点（与 page_cache_end 配对；route/*.php 顶部 `if (page_cache_start($k)) return;`）。
 * 键先叠加内容版本 page_cache_vkey()（跨 FPM worker 即时失效，R6-04）。
 * 命中：（有 $onHit 先回调，如补发 Content-Type）→ echo 还原后的整页 → 返回 TRUE（调用方须立即结束）；
 * 空串视为历史脏缓存，删除后走未命中回填（自愈）。未命中：抢渲染锁成功者持锁渲染
 * （锁 10s 自过期 + shutdown 兜底释放）；抢锁失败者复查缓存 5×100ms，仍无则降级本地渲染。
 * ⚠ DEBUG != 0 直接返回 FALSE 且**不开缓冲**（此时不得依赖 end 回填）。返回 FALSE 的未命中
 * 路径已 ob_start()，须由 page_cache_end() 收口输出并写缓存。
 * @param string $cacheKey 页缓存键（不含版本后缀）
 * @param callable|null $onHit 命中时输出前回调（无参）
 * @return bool TRUE=已命中并输出完毕；FALSE=未启用/未命中（继续渲染）
 */
function page_cache_start($cacheKey, $onHit = NULL)
{
    if (DEBUG != 0) return FALSE;
    $cacheKey = page_cache_vkey($cacheKey);
    try {
        $cached = cache_get($cacheKey);
        // 空串=历史脏缓存（异常窗口期 page_cache_end 把空输出写入了缓存，命中即永久空白页，
        // 入口拆分验收实测）：视为未命中并自愈删除，随后走正常渲染回填
        // 压缩条目解不开（v1 旧格式 "\x00GZ" / 损坏）同样视为未命中并删除，随后重新渲染回填新格式
        $html = ($cached === '' || $cached === null || $cached === false) ? $cached : page_cache_unpack($cached);
        if ($cached === '' || ($html === null && $cached !== null)) {
            try { cache_delete($cacheKey); } catch (\Throwable $e) {}
        } elseif ($cached !== null && $cached !== false) {
            if ($onHit) call_user_func($onHit);
            echo $html;
            return TRUE;
        }
    } catch (\Throwable $e) {}
    // 渲染锁（击穿兜底）：抢锁成功者渲染回填；抢锁失败者短等待复查缓存，
    // 超时仍无缓存则降级本地渲染（并发兜底，锁 10s 自过期防死锁）
    $GLOBALS['_page_lock'] = NULL;
    try {
        $lockKey = 'page_lock_' . $cacheKey;
        if (cache_get($lockKey) === null) {
            cache_set($lockKey, 1, 10);
            $GLOBALS['_page_lock'] = $lockKey;
            // shutdown 兜底释放（上线前终审补）：message()/404/跳转等提前退出路径
            // 不经 page_cache_end，若锁仍由本请求持有则在此释放（正常路径已置 NULL，钩子为空操作）
            register_shutdown_function(function () {
                if (!empty($GLOBALS['_page_lock'])) {
                    try { cache_delete($GLOBALS['_page_lock']); } catch (\Throwable $e) {}
                    $GLOBALS['_page_lock'] = NULL;
                }
            });
        } else {
            for ($i = 0; $i < 5; $i++) {
                usleep(100000);
                $cached = cache_get($cacheKey);
                // P2（审核轮）：与首查口径对齐——空串永不视为命中
                if ($cached !== null && $cached !== false && $cached !== '') {
                    $html = page_cache_unpack($cached);
                    if ($html === null) break;   // 不可用压缩条目：不等了，直接本地渲染（回填时覆盖）
                    if ($onHit) call_user_func($onHit);
                    echo $html;
                    return TRUE;
                }
            }
        }
    } catch (\Throwable $e) {}
    ob_start();
    return FALSE;
}

/**
 * 页面级缓存-终点：结束缓冲、写入缓存并输出
 * @param string $cacheKey 缓存键
 * @param int $ttl 缓存秒数
 */
function page_cache_end($cacheKey, $ttl = 1800)
{
    if (DEBUG != 0) return;
    $cacheKey = page_cache_vkey($cacheKey);
    $out = ob_get_clean();
    if ($out !== false) {
        // 空输出不回填缓存：把空串写进页缓存=此后所有命中都是空白页
        if ('' !== $out) {
            try { cache_set($cacheKey, page_cache_pack($out), $ttl); } catch (\Throwable $e) {}
        }
        echo $out;
    }
    // 释放渲染锁
    if (!empty($GLOBALS['_page_lock'])) {
        try { cache_delete($GLOBALS['_page_lock']); } catch (\Throwable $e) {}
        $GLOBALS['_page_lock'] = NULL;
    }
}

/**
 * 轮播条目装配：优先横版海报 pic_slide（slide_type=poster），无则回落竖版缩略图 pic（slide_type=thumb）。
 * pic_slide 为站内相对路径（如 upload/poster/...）时补成站点根相对 URL；http(s):// 与 // 原样保留。
 * @param array $list 视频条目（需 0 起索引，模板以 $k==0 判断首张）
 * @return array 每项新增 slide_img / slide_type
 */
function slide_items_decorate(array $list): array
{
    foreach ($list as $i => $v) {
        if (!is_array($v)) continue;
        $ps = trim((string)($v['pic_slide'] ?? ''));
        if ('' !== $ps && !preg_match('#^(https?:)?//#i', $ps)) {
            $ps = '/' . ltrim($ps, '/');
        }
        $list[$i]['slide_img'] = '' !== $ps ? $ps : (string)($v['pic'] ?? '');
        $list[$i]['slide_type'] = '' !== $ps ? 'poster' : 'thumb';
    }
    return $list;
}

