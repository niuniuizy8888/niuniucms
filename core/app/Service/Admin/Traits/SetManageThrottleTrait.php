<?php
namespace App\Service\Admin\Traits;

/**
 * 限速设置（throttlePage/saveThrottle）（从 SetManageService 拆分，Trait 组合）
 *
 * 修复（真机实测「保存」HTTP 500 空响应体）：本 Trait 通篇调用 `param_int()`
 * ——**该函数在本仓根本不存在**（只有 `param()`/`param_force()` 与后台的 `admin_param_int()`），
 * 而它在 `App\Service\Admin\Traits` 命名空间下不会被回退到全局 ⇒ 每次保存都
 * `Call to undefined function App\Service\Admin\Traits\param_int()` 致命错误
 * （日志 runtime/log 下的 php_fatal 通道，页面表现为 500 空响应，看起来像"点了没反应"）。
 * 统一改用后台助手 `admin_param_int()`（签名一致：key/default/min/max，且会夹紧范围）。
 */
trait SetManageThrottleTrait
{
    /**
     * 限速设置页面数据
     */
    public static function throttlePage(int $uid): array
    {
        $throttleFile = \nncms_runtime_path('throttle.php');
        $throttle = is_file($throttleFile) ? include $throttleFile : array();
        if (!is_array($throttle)) $throttle = array();

        // 登录限流（同 IP 尝试次数/窗口）：前后台登录均经 login_rate_limits() 读取，未保存过显示后台默认 10/300
        $loginThrottle = array(
            'max' => intval($throttle['login_max'] ?? 10),
            'window' => intval($throttle['login_window'] ?? 300),
        );

        return array(
            'header' => array(
                'title' => '限速设置',
            ),
            'throttle' => $throttle,
            'login_throttle' => $loginThrottle,
            'input' => array(
                'enable' => form_radio('enable', array('1' => '开启', '0' => '关闭'), array_value($throttle, 'enable', 0) ? 1 : 0),
                'session_limit' => form_text('session_limit', array_value($throttle, 'session_limit', 30)),
                'session_window' => form_text('session_window', array_value($throttle, 'session_window', 60)),
                'ip_limit' => form_text('ip_limit', array_value($throttle, 'ip_limit', 10)),
                'ip_window' => form_text('ip_window', array_value($throttle, 'ip_window', 600)),
                'login_max' => form_text('login_max', array_value($loginThrottle, 'max', 10)),
                'login_window' => form_text('login_window', array_value($loginThrottle, 'window', 300)),
                'register_max' => form_text('register_max', array_value($throttle, 'register_max', 10)),
                'register_window' => form_text('register_window', array_value($throttle, 'register_window', 3600)),
                'verify_blacklist' => form_radio('verify_blacklist', array('1' => '开启', '0' => '关闭'), array_value($throttle, 'verify_blacklist', 1) ? 1 : 0),
                'verify_robot' => form_radio('verify_robot', array('1' => '开启', '0' => '关闭'), array_value($throttle, 'verify_robot', 0) ? 1 : 0),
                'bot_list' => form_textarea('bot_list', implode("\n", array_value($throttle, 'bot_list', array())), '', '100%', 80),
                'url_prefix_allow' => form_text('url_prefix_allow', implode(',', array_value($throttle, 'url_prefix_allow', array('admin', 'api')))),
                // api.php 机器通道：令牌豁免 + 无/错令牌的洪水窗口
                'api_token_exempt' => form_radio('api_token_exempt', array('1' => '开启', '0' => '关闭'), array_value($throttle, 'api_token_exempt', 1) ? 1 : 0),
                'api_limit' => form_text('api_limit', array_value($throttle, 'api_limit', 30)),
                'api_window' => form_text('api_window', array_value($throttle, 'api_window', 600)),
            ),
            'safe_token' => bull_token_set($uid, 'admin_throttle'),
        );
    }

    /**
     * 保存限速设置
     */
    public static function saveThrottle(int $uid): array
    {
        self::verifyToken($uid, 'admin_throttle');

        // 读取当前配置
        $throttleFile = \nncms_runtime_path_w('throttle.php');
        $throttle = is_file($throttleFile) ? include $throttleFile : array();
        if (!is_array($throttle)) $throttle = array();

        // 更新 throttle.php
        $throttle['enable'] = admin_param_int('enable', 0) ? true : false;
        $throttle['session_limit'] = admin_param_int('session_limit', 30, 1, 10000);
        $throttle['session_window'] = admin_param_int('session_window', 60, 10, 86400);
        $throttle['ip_limit'] = admin_param_int('ip_limit', 10, 1, 10000);
        $throttle['ip_window'] = admin_param_int('ip_window', 600, 10, 86400);
        $throttle['verify_blacklist'] = admin_param_int('verify_blacklist', 1) ? true : false;
        $throttle['verify_robot'] = admin_param_int('verify_robot', 0) ? true : false;

        $botListText = param('bot_list', '');
        $botList = array_filter(array_map('trim', explode("\n", $botListText)));
        $throttle['bot_list'] = $botList ?: array('Googlebot', 'Baiduspider', 'bingbot');

        $prefixText = param('url_prefix_allow', 'admin,api');
        $throttle['url_prefix_allow'] = array_filter(array_map('trim', explode(',', $prefixText)));

        // api.php 机器通道：令牌豁免 + 洪水窗口。
        // 关掉 api_token_exempt 会让商店 rsync-task worker 的逐页回调撞 429（任务转 error），
        // 故此处**默认保持开启**：只有显式提交 0 才关。
        $throttle['api_token_exempt'] = admin_param_int('api_token_exempt', 1) ? true : false;
        $throttle['api_limit'] = admin_param_int('api_limit', 30, 1, 100000);
        $throttle['api_window'] = admin_param_int('api_window', 600, 10, 86400);

        // 登录/注册限流与其余项同落 throttle.php（运行时可写配置）。
        // 不再改写 core/config/route_meta.php：core/ 部署只读，且 var_export 回写会冲掉该文件注释。
        $loginMax = admin_param_int('login_max', 10, 1, 10000);
        $loginWindow = admin_param_int('login_window', 300, 10, 86400);
        $throttle['login_max'] = $loginMax;
        $throttle['login_window'] = $loginWindow;
        $throttle['register_max'] = admin_param_int('register_max', 10, 1, 10000);
        $throttle['register_window'] = admin_param_int('register_window', 3600, 10, 86400);

        $content = "<?php\nreturn " . var_export($throttle, true) . ";\n";
        if (false === file_put_contents_try($throttleFile, $content)) {
            xn_log('throttle save failed: ' . basename($throttleFile) . ' not writable', 'error');
            return array('code' => -1, 'message' => '保存限速配置失败（配置文件不可写）');
        }

        cache_delete('throttle_config');
        admin_audit_log('throttle_save', array(
            'enable' => $throttle['enable'] ? 1 : 0,
            'ip_limit' => $throttle['ip_limit'],
            'login_max' => $loginMax,
            'api_token_exempt' => $throttle['api_token_exempt'] ? 1 : 0,
            'api_limit' => $throttle['api_limit'],
        ));

        return array('code' => 0, 'message' => '保存成功');
    }
}
