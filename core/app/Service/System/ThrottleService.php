<?php
namespace App\Service\System;

/**
 * 通用限流服务（固定窗口计数，APCu 原子通道优先）
 * 场景化固定窗口计数。
 * 安全修复：原 cache_get → ++ → cache_set 为「读-改-写」三步，并发请求可在窗口内
 * 互相覆盖计数实现限流穿透（爆破场景实测可放大并发绕过）。现优先 APCu 原子计数（单机共享内存、
 * 天然原子），未启用 APCu 时退到本地 flock 文件计数；两者都不可用时保留原缓存路径兜底。
 * 说明：计数为「每 Web 节点」粒度（爆破防护的正确粒度）；跨节点共享计数在接入 Redis 后可替换。
 */
class ThrottleService
{
    /**
     * 本地计数文件目录可注入（默认 APP_PATH/runtime/throttle；
     * 单测以独立临时目录注入，保持进程内 hermetic——文件计数天然跨进程持久，测试不能共享）
     */
    public static $storeDir = null;
    /**
     * 计数并判断是否允许继续
     * @param string $scene 场景名（login/register/upload/...）
     * @param string $key 限流键（IP/UID/邮箱等）
     * @param int $maxAttempts 窗口内最大次数
     * @param int $window 窗口秒数
     * @return bool true=放行 false=超限
     */
    public static function check(string $scene, string $key, int $maxAttempts = 5, int $window = 300): bool
    {
        $rateKey = 'throttle_' . $scene . '_' . md5((string)$key);
        $now = intval(_SERVER('time')) ?: time();

        $count = self::countAtomicApcu($rateKey, $window);
        if ($count === null) {
            $count = self::countAtomicFile($rateKey, $window, $now);
        }
        if ($count === null) {
            $count = self::countFallbackCache($rateKey, $window, $now);
        }
        return $count <= $maxAttempts;
    }

    /**
     * APCu 原子计数：add(0) 建窗 + inc(+1) 递增
     * 判定原则：仅当本进程内首次调用成功读写才启用 APCu 通道；任何 False 立即永久回退文件层
     * （避免「键不存在被当作放行」造成限流静默失效，如 apc.enabled=0/CLI 未启用场景）
     * @return int|null null 表示需走文件层
     */
    private static function countAtomicApcu(string $rateKey, int $window)
    {
        static $apcuUsable = null;
        if ($apcuUsable === false) {
            return null;
        }
        if (!function_exists('apcu_add') || !function_exists('apcu_inc')) {
            $apcuUsable = false;
            return null;
        }
        $ok = false;
        apcu_add($rateKey, 0, $window); // 建窗口（已存在则失败，不影响后续递增）
        $count = apcu_inc($rateKey, 1, $ok, $window);
        if ($ok === false || $count === false || !is_int($count)) {
            if ($apcuUsable === null) {
                $apcuUsable = false; // 首次即不可用：本机回退文件层
            }
            return null; // 运行中偶发失败（恰过期等）：本请求走文件层，不推翻已确认可用的判断
        }
        $apcuUsable = true;
        return intval($count);
    }

    /**
     * 本地文件 + flock 原子计数（APCu 不可用时的单机方案）
     * @return int|null null 表示文件锁不可用（交给缓存兜底）
     */
    private static function countAtomicFile(string $rateKey, int $window, int $now)
    {
        if (self::$storeDir !== null) {
            $dir = self::$storeDir;
        } else {
            $appPath = defined('APP_PATH') ? APP_PATH : dirname(dirname(dirname(dirname(__DIR__)))) . '/';
            $dir = $appPath . 'runtime/throttle';
        }
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return null;
        }
        $file = $dir . '/' . md5($rateKey . '|' . $window) . '.cnt'; // 键含窗口参数，防不同调用方读同一文件
        $fp = @fopen($file, 'c+');
        if (!$fp || !@flock($fp, LOCK_EX)) {
            if ($fp) {
                @flock($fp, LOCK_UN);
                @fclose($fp);
            }
            return null;
        }
        $count = 1;
        $first = $now;
        $raw = stream_get_contents($fp);
        $state = $raw !== '' ? @unserialize($raw, array('allowed_classes' => false)) : false;
        if (is_array($state) && isset($state['first'], $state['count']) && $now >= intval($state['first']) && ($now - intval($state['first'])) <= $window) {
            $count = intval($state['count']) + 1;
            // 审查轮 U-1（R2 遗留）：窗口仍有效时保留原起点，固定窗口不随每次尝试滑动延长
            // （旧实现每次都写 first=now ⇒ 持续尝试则窗口永不结束，限流形同虚设）。
            $first = intval($state['first']);
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, serialize(array('count' => $count, 'first' => $first)));
        fflush($fp);
        @flock($fp, LOCK_UN);
        fclose($fp);
        return $count;
    }

    /**
     * 缓存层兜底（保持原语义：非原子，仅在无本地写能力时使用）
     */
    private static function countFallbackCache(string $rateKey, int $window, int $now): int
    {
        $data = cache_get($rateKey);
        if (!is_array($data)) {
            $data = array('count' => 0, 'first' => $now);
        }
        if ($now - $data['first'] > $window) {
            $data = array('count' => 0, 'first' => $now);
        }
        $data['count']++;
        cache_set($rateKey, $data, $window);
        return intval($data['count']);
    }
}
