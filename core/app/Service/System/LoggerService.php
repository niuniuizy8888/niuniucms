<?php
namespace App\Service\System;

/**
 * 分级日志服务（按通道落盘、超限覆盖与敏感信息掩码）
 * 原 xn_log 实现搬入：按月目录、行式格式化、大小控制；新增级别与结构化上下文
 *
 * **不使用 static 的 PSR-3 LoggerInterface 实现**（不要试图加 `implements`）：
 * PSR-3 的接口方法全部是**实例方法**，而 PHP 语言层面**不允许**用静态方法满足实例接口
 * （实测 `Fatal error: Cannot make non static method ... static in class ...`）。
 * 本服务的全部方法都是静态的（全站 100+ 处 `LoggerService::xxx()` 调用依赖此形态），
 * 改成实例方法会大范围破坏调用点，收益仅是「名义合规」，不值当。
 *
 * ⇒ 因此**不要声称本类实现 PSR-3**。接口形状仍保留在
 * `core/psr/Log/LoggerInterface.php`（供将来改造成实例服务时直接 implements）。
 *
 * ⚠ 参数语义（易混）：
 *   - `write(string $channel, string $level, $msg, array $context)` 与
 *     `channel(string $channel, string $level, string $msg, array $context)` 是**本服务原生 API**：
 *     通道名在前，决定落盘到 `runtime/log/<Ym>/<通道>.php`。
 *   - 形似 PSR-3 的 `error()/info()` 等按 PSR-3 **参数顺序**（首参 $message），
 *     本服务把它当**通道名**使用，正文放 `$context['msg']`。
 *     ⇒ 需要「通道 + 正文」时请用 `channel()` / `write()`。
 */
class LoggerService
{
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO  = 'info';
    const LEVEL_WARN  = 'warn';
    const LEVEL_ERROR = 'error';

    /**
     * 审计通道判定：admin_audit / *_audit 在线上（DEBUG=0）也必须落盘。
     * 发现：旧级别过滤把非 error 通道全丢，导致 admin_audit_log() 全仓 94 处调用
     * 自上线起从未写出一个字节（runtime/log/ 下无任何 admin_audit* 文件），后台"谁删了什么"
     * 只能靠 binlog + access_log 反推。日志静音不该波及审计，故单列白名单。
     */
    private static function isAuditChannel(string $channel): bool
    {
        // 上线前审计修复（L-A2）：admin_login* 通道（后台登录成功/被拒审计）此前被 DEBUG=0 级别过滤
        // 静默丢弃——生产环境后台登录零留痕。并入恒写白名单。
        return str_starts_with($channel, 'admin_audit') || '_audit' === substr($channel, -6) || str_starts_with($channel, 'admin_login');
    }

    /**
     * 掩码日志正文里的敏感值：覆盖 query string / 常见 key=value / JSON 键值三类形态，
     * 防下载 URL 签名、token、口令等被直接落盘。
     */
    private static function maskSecrets(string $text): string
    {
        if ('' === $text) return $text;
        $text = preg_replace('#([?&](?:safe_token|token|api_key|apikey|auth_key|password|pass|secret)=)[^&\s"\']*#i', '$1***', $text);
        $text = preg_replace('#\b((?:authorization|x-api-key|api[_-]?key|auth[_-]?key|safe[_-]?token|token|password|pass|secret)\s*[:=]\s*)([^\s,"\']+)#i', '$1***', $text);
        $text = preg_replace('#("(?:safe_token|token|api_key|apikey|auth_key|password|pass|secret)"\s*:\s*")[^"]*(")#i', '$1***$2', $text);
        return $text;
    }

    /**
     * 单文件大小控制：超过上限后直接清空当前文件，由下一次 append 重新起写。
     * 不保留历史轮转文件，口径对齐「日志大于 10MB 自动覆盖」。
     */
    private static function truncateIfOversized(string $logfile, int $maxMb): void
    {
        if ($maxMb <= 0 || !is_file($logfile)) return;
        clearstatcache(true, $logfile);
        if (filesize($logfile) <= $maxMb * 1048576) return;
        @file_put_contents($logfile, '', LOCK_EX);
        clearstatcache(true, $logfile);
    }

    /**
     * 写日志
     * @param string $channel 通道（文件分类名，兼容原 xn_log 的 $file）
     * @param string $level debug/info/warn/error
     * @param string $msg 日志内容
     * @param array $context 结构化上下文（可选）
     */
    public static function write(string $channel, string $level, string $msg, array $context = array())
    {
        // 上线前安全审计加固：通道名归一化白名单。
        // 通道直接拼进日志文件名（$logpath/$channel.php）。现有约 190 处调用点（xn_log 约 67 +
        // admin_audit_log 约 121 + 直接 write 1），通道名全部是代码内常量（admin_audit_log 的 $file
        // 形参默认 'admin_audit'，等价于字面量）。
        // 此闸防未来调用方把外部输入当通道名造成路径穿越/审计通道伪装。
        if (!preg_match('#^[a-zA-Z0-9_-]+$#', $channel)) $channel = 'error';
        $level = in_array($level, array(self::LEVEL_DEBUG, self::LEVEL_INFO, self::LEVEL_WARN, self::LEVEL_ERROR), true) ? $level : self::LEVEL_INFO;
        // 级别过滤：error 恒写；审计通道恒写（后台写操作留痕是硬约定，不能被日志级别开关静默关掉）；
        // 其余仅在 DEBUG 模式或通道名含 error 时写（保持 xn_log 原语义）
        if (0 == DEBUG && self::LEVEL_ERROR !== $level && false === strpos($channel, 'error') && false === self::isAuditChannel($channel)) return;

        $conf = _SERVER('conf');
        $time = intval(_SERVER('time')) ?: time();
        $ip = _SERVER('ip');
        $uid = intval(G('uid'));
        $day = date('Ym', $time); // 按照月存放，否则 Ymd 目录太多。
        $mtime = date('Y-m-d H:i:s');
        // 审计修复（F-06）：日志行携带 REQUEST_URI，而退出/写操作链接把 safe_token
        // （及 api.php 的 token）放查询串——原样落盘=令牌明文进日志。写入前统一掩码（nginx 侧
        // log_format 只掩 token=，应用侧补齐 safe_token=）。
        $url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $url = preg_replace('#([?&](?:safe_token|token)=)[^&\s"\']*#i', '$1***', $url);
        $logpath = $conf['log_path'] . $day;
        !is_dir($logpath) and mkdir($logpath, 0777, true);

        // 上线前终检（N-07）：原仅剥 \r\n/\n/\t —— **孤立的 \r 未剥**，而日志正文可含攻击者可控文本
        // （URL/采集远端文案），一旦 web 层放开 runtime 直访即可被用来伪造日志行（日志文件自带 `<?php exit;` 头）。
        $s = str_replace(array("\r\n", "\n", "\r", "\t"), ' ', self::maskSecrets((string)$msg));
        if (!empty($context)) {
            $s .= "\t" . self::maskSecrets(xn_json_encode($context));
        }
        $s = "<?php exit;?>\t$mtime\t$level\t$ip\t$url\t$uid\t$s\r\n";

        $logfile = $logpath . "/$channel.php";

        // 自动覆盖：文件超过限制后清空当前文件，避免线上日志越积越大。
        $maxMb = (int)($conf['log_max_size'] ?? 10);
        self::truncateIfOversized($logfile, $maxMb);

        @error_log($s, 3, $logfile);
    }

    /**
     * 本服务原生 API：通道 + 级别 + 消息（需要「通道与消息分离」时用这个）
     */
    public static function channel(string $channel, string $level, string $msg, array $context = array())
    {
        self::write($channel, $level, $msg, $context);
    }

    /* ==========================================================================
     * PSR-3 实现（裁剪版）
     * 注意：按 PSR-3 规范，$message 位于第一位；本服务的端口语义把 $message 当**通道名**，
     * 正文放 $context['msg']（缺省为空串）。需要「通道 + 正文」两参数请用 channel()/write()。
     * ======================================================================== */

    /** @inheritDoc */
    public static function emergency($message, array $context = array())
    {
        self::write((string)$message, self::LEVEL_ERROR, self::psr3Body($context), $context);
    }

    /** @inheritDoc */
    public static function alert($message, array $context = array())
    {
        self::write((string)$message, self::LEVEL_ERROR, self::psr3Body($context), $context);
    }

    /** @inheritDoc */
    public static function critical($message, array $context = array())
    {
        self::write((string)$message, self::LEVEL_ERROR, self::psr3Body($context), $context);
    }

    /** @inheritDoc */
    public static function error($message, array $context = array())
    {
        self::write((string)$message, self::LEVEL_ERROR, self::psr3Body($context), $context);
    }

    /** @inheritDoc */
    public static function warning($message, array $context = array())
    {
        self::write((string)$message, self::LEVEL_WARN, self::psr3Body($context), $context);
    }

    /** @inheritDoc */
    public static function notice($message, array $context = array())
    {
        self::write((string)$message, self::LEVEL_INFO, self::psr3Body($context), $context);
    }

    /** @inheritDoc */
    public static function info($message, array $context = array())
    {
        self::write((string)$message, self::LEVEL_INFO, self::psr3Body($context), $context);
    }

    /** @inheritDoc */
    public static function debug($message, array $context = array())
    {
        self::write((string)$message, self::LEVEL_DEBUG, self::psr3Body($context), $context);
    }

    /** @inheritDoc */
    public static function log($level, $message, array $context = array())
    {
        self::write((string)$message, (string)$level, self::psr3Body($context), $context);
    }

    /**
     * PSR-3 调用的正文提取：取 $context['msg']，并把它从结构化上下文中摘掉
     * （避免同一段文字既当正文又在 JSON 里重复一遍）。
     */
    private static function psr3Body(array &$context): string
    {
        if (!isset($context['msg'])) return '';
        $body = (string)$context['msg'];
        unset($context['msg']);
        return $body;
    }
}
