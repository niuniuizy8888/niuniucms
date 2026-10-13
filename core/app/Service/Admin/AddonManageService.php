<?php
namespace App\Service\Admin;

/**
 * 后台插件管理服务：本地插件列表 / 启停 / 安装 / 卸载（token + 审计）
 *
 * 插件系统改造（R0）：
 *  - R0-1 数据源接线：AddonService::listing() 已惰性 init()（原列表恒空根因在 Service 侧修复）
 *  - R0-2 键归一：真实数据键 enable/installed → 展示键 enabled（原读 enabled 恒 0，状态列/按钮方向全错）
 *  - R0-5 toggle：op 白名单 + AddonService::$lastError 透传（原失败恒显示"操作失败"无原因）
 */
class AddonManageService
{
    /**
     * 本地插件列表页视图数据（后台 addon.php?list，GET）。
     * AddonService::listing() 取 conf.json 原始行后逐行归一展示键（enable→enabled 等），
     * 计算 setting_url（已安装且 setting.php 在位才给入口），并在商店域名配置生效时补充
     * 商店元信息/是否有更新（storePlugin/storeHasUpgrade，商店不可达静默跳过），
     * 已安装插件另附 overwrite 基线漂移告警（ow_changed/ow_missing）。
     *
     * @param int $uid 当前管理员 uid，签发 admin_addon 场景令牌
     * @return array {input, arrlist(展示键已归一的插件行), safe_token(string|false), store_on(bool), header}
     */
    public static function listPage(int $uid): array
    {
        $arrlist = \App\Service\System\AddonService::listing(array(), array(), 1, 100, 1);
        // B3：商店检查更新（cloud_api 配置后生效；store_plugins_v1 600s 缓存，商店不可达时静默跳过）
        // 数据源：`{store}api.php` 顶层 plugins 数组（**不是** index.php?api=manifest——那只含模板）
        $storeOn = '' !== \App\Service\System\AddonService::storeBase();
        foreach ($arrlist as &$addon) {
            $addon['enabled'] = (int)($addon['enable'] ?? 0);          // R0-2：键归一（原读 enabled 恒 0）
            $addon['installed'] = (int)($addon['installed'] ?? 0);
            $addon['dir'] = (string)($addon['dir'] ?? '');
            $addon['name'] = (string)($addon['name'] ?? '');
            $addon['version'] = (string)($addon['version'] ?? '');
            $addon['brief'] = (string)($addon['brief'] ?? '');
            // 修复「后台所有插件看不到/进不了设置页」：listing() 返回 conf.json 原始行，
            // 从不携带 setting_url（该字段仅 readByDir() 计算）→ 此前 ?? '' 恒空、设置按钮整体消失。
            // 与 readByDir 同口径：已安装且 setting.php 在位才给入口。
            $addon['setting_url'] = ($addon['installed'] === 1 && is_file(\APP_PATH . 'plugins/' . $addon['dir'] . '/setting.php'))
                ? (string)url('addon-setting', array('dir' => $addon['dir']), TRUE) : '';
            $addon['have_upgrade'] = FALSE;
            $addon['store_version'] = '';
            // 商店元信息（作者/简介/下载量/更新时间）——来自 api.php 的 plugins 条目，
            // 仅用于展示补充；本地 conf.json 仍是权威（本地未填时才回落到商店值）
            $addon['store_author'] = '';
            $addon['store_brief'] = '';
            $addon['store_downloads'] = 0;
            $addon['store_updated_at'] = 0;
            $addon['in_store'] = FALSE;
            if ($storeOn) {
                $sp = \App\Service\System\AddonService::storePlugin($addon['dir']);
                if (NULL !== $sp) {
                    $addon['in_store'] = TRUE;
                    $addon['store_author'] = (string)$sp['author'];
                    $addon['store_brief'] = (string)$sp['brief'];
                    $addon['store_downloads'] = (int)$sp['downloads'];
                    $addon['store_updated_at'] = (int)$sp['updated_at'];
                    if ('' === $addon['brief']) $addon['brief'] = (string)$sp['brief'];
                    if (1 === $addon['installed']) {
                        $v = \App\Service\System\AddonService::storeHasUpgrade($addon['dir'], $addon['version']);
                        if (FALSE !== $v) { $addon['have_upgrade'] = TRUE; $addon['store_version'] = $v; }
                    }
                }
            }
            // overwrite 基线漂移：核心升级改动被覆盖文件 → 插件副本静默过时，列表显式告警
            $addon['ow_total'] = 0;
            $addon['ow_changed'] = array();
            $addon['ow_missing'] = array();
            if (1 === $addon['installed']) {
                $d = \App\Service\System\AddonService::overwriteDrift($addon['dir']);
                $addon['ow_total'] = (int)$d['total'];
                $addon['ow_changed'] = $d['changed'];
                $addon['ow_missing'] = $d['missing'];
            }
        }
        unset($addon);
        bull_token_set($uid, 'admin_addon');
        return array(
            'input' => array(),
            'arrlist' => $arrlist ?: array(),
            'safe_token' => bull_token_get($uid, 'admin_addon'),
            'store_on' => $storeOn,
            // 商店可达性：拉取失败时视图**不能**把「没查到」当「商店未收录」（旧实现两者不可分辨）
            'store_ok' => $storeOn && !\App\Service\System\AddonService::storeFetchFailed(),
            'header' => array('title' => '插件'),
        );
    }

    /**
     * 插件生命周期操作统一入口（后台 addon.php，POST，场景令牌 admin_addon）。
     * op 白名单 enable/disable/install/uninstall/update（非法 op 记 addon_bad_op 审计并拒），
     * 分派到 AddonService 对应方法，失败时透传 AddonService::$lastError 原因；
     * 每次操作写 addon_<op> 审计。注意启用前 AddonService 侧还有 hook 头契约 + 语法闸校验（插件=受信代码口径）。
     *
     * @param int $uid 当前管理员 uid
     * @return void 不返回：恒以 message()（TRUE === $result ? 0 : -1）终止请求
     */
    public static function toggle(int $uid)
    {
        // 发布前检查修复（E-4）：admin_param_text 第二参为默认值——原误传 64
        $safe_token = admin_param_text('safe_token', '');
        admin_bull_token_guard($uid, 'admin_addon', $safe_token);
        $dir = admin_param_text('dir', '', 128);
        $op = admin_param_text('op', '', 16);
        // R0-5：op 白名单（update=B3 商店一键更新）
        $ops = array('enable', 'disable', 'install', 'uninstall', 'update');
        if (!in_array($op, $ops, true)) {
            admin_audit_log('addon_bad_op', array('dir' => $dir, 'op' => $op));
            message(-1, '未知操作');
        }
        \App\Service\System\AddonService::$lastError = '';
        if ('enable' === $op) {
            $result = \App\Service\System\AddonService::enable($dir);
        } elseif ('disable' === $op) {
            $result = \App\Service\System\AddonService::disable($dir);
        } elseif ('install' === $op) {
            $result = \App\Service\System\AddonService::install($dir);
        } elseif ('update' === $op) {
            $result = \App\Service\System\AddonService::storeUpdate($dir);
        } else {
            $result = \App\Service\System\AddonService::uninstall($dir);
        }
        $err = (string)\App\Service\System\AddonService::$lastError;
        // 修复静默失败：bool 结果真实反馈（此前恒显示"操作完成"）；R1-2/R1-4 校验失败原因透传
        $msg = TRUE === $result ? '操作成功' : ('操作失败' . ('' !== $err ? '：' . $err : ''));
        admin_audit_log('addon_' . $op, array('dir' => $dir, 'result' => $result, 'error' => $err));
        message(TRUE === $result ? 0 : -1, $msg);
    }

    /**
     * R2-3 客户端插件上传安装链（补完）：$_FILES 收包 → AddonService::installFromZip
     * （条目闸/落位/verifyAddonCode/回滚）→ sha256 审计台账。上传成功≠启用：插件落 plugins/ 后
     * installed=0/enable=0，启用须在列表中显式 install/enable。
     */
    public static function upload(int $uid)
    {
        $safe_token = admin_param_text('safe_token', '');
        admin_bull_token_guard($uid, 'admin_addon', $safe_token);
        $replace = ('1' === admin_param_text('replace', ''));
        if (!isset($_FILES['file']) || !is_array($_FILES['file']) || (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($_FILES['file']['tmp_name']) || !is_file((string)$_FILES['file']['tmp_name'])) {
            admin_audit_log('addon_upload', array('result' => FALSE, 'error' => 'upload_error'));
            message(-1, '文件上传失败');
        }
        $tmp = (string)$_FILES['file']['tmp_name'];
        $name = (string)($_FILES['file']['name'] ?? '');
        'zip' === strtolower((string)pathinfo($name, PATHINFO_EXTENSION)) or message(-1, '仅支持 .zip 插件包');
        $dir = \App\Service\System\AddonService::installFromZip($tmp, $replace);
        $err = (string)\App\Service\System\AddonService::$lastError;
        admin_audit_log('addon_upload', array(
            'dir' => FALSE !== $dir ? $dir : '',
            'name' => $name,
            'size' => (int)@filesize($tmp),
            'sha256' => (string)@hash_file('sha256', $tmp),
            'replace' => $replace,
            'result' => FALSE !== $dir,
            'error' => $err,
        ));
        message(FALSE !== $dir ? 0 : -1, FALSE !== $dir
            ? ('插件包已安装到 plugins/' . $dir . '（未启用；请在列表中"安装"后再"启用"）')
            : ('上传安装失败' . ('' !== $err ? '：' . $err : '')));
    }
}
