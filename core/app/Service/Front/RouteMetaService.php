<?php
namespace App\Service\Front;

/**
 * 路由 meta 声明式校验（按 route_meta.php 声明校验路由入参与守卫）
 * 集中声明表 config/route_meta.php，在路由分发前按规则预校验：
 *  - auth：要求登录（未登录 → json 错误 / 跳转登录页）
 *  - throttle：限流（scene/max/window，经 ThrottleService）
 */
class RouteMetaService
{
    private static $meta = null;

    /**
     * 加载路由声明表（静态缓存）
     */
    public static function load(): array
    {
        if (null !== self::$meta) return self::$meta;
        $file = APP_PATH . 'core/config/route_meta.php';
        $meta = is_file($file) ? include $file : array();
        self::$meta = is_array($meta) ? $meta : array();
        return self::$meta;
    }

    /**
     * 校验当前路由/动作
     * @return bool true=通过；false=已被拦截（输出响应并终止）
     */
    public static function check(string $route, string $action, int $uid, string $method): bool
    {
        $meta = self::load();
        $rules = $meta[$route][$action] ?? $meta[$route]['*'] ?? array();
        if (empty($rules)) return true;

        // auth：需要登录
        if (!empty($rules['auth']) && $uid <= 0) {
            $isJson = _SERVER('json') || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
            if ($isJson) {
                message(-1, lang('user_not_login'));
            } else {
                message(0, jump('请先登录', url('user-login'), 3));
            }
            return false;
        }

        // throttle：限流
        if (!empty($rules['throttle']) && 'POST' === $method) {
            $t = $rules['throttle'];
            $key = (string)(_SERVER('longip') ?: '');
            if (!\App\Service\System\ThrottleService::check(
                (string)($t['scene'] ?? 'route'),
                $key,
                (int)($t['max'] ?? 10),
                (int)($t['window'] ?? 300)
            )) {
                message(-1, lang('login_too_frequent'));
                return false;
            }
        }
        return true;
    }
}
