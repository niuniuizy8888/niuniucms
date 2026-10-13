<?php
namespace App\Service\Collection;

class ApiMiscService
{
    /**
     * 栏目备份目录（测试注入用，NULL = APP_PATH.'runtime/backup/'）
     */
    public static $backupDir = NULL;

    /**
     * 保留的栏目备份份数（超出按时间从旧到新清理）
     */
    const BACKUP_KEEP = 10;

    /**
     * 清空分类表（**整张 nncms_cate = 全站栏目**）
     *
     * 事故加固：本方法执行的是 TRUNCATE（DDL，不可回滚、binlog 不保留行数据），
     * 一次点击即清空全站栏目，且 nncms_vod.cid / nncms_art.cid 同时悬空——前台表现为
     * 详情页 500（$cate_nav 传成 null）、播放/列表/文章/会员页一律 404。故加三道闸：
     *   ① 引用检查：仍有内容挂在栏目上时默认拒绝，必须调用方显式 force=1；
     *   ② 执行前先把整表导成可直接回灌的 SQL 备份，**备份写不进去就中止**（fail-closed）；
     *   ③ 审计日志带栏目数/引用数/备份路径，拒绝与中止同样留痕。
     *
     * @param string $code  采集接口标识（仅审计用）
     * @param int    $force 1=调用方已向用户明示破坏范围并完成二次确认
     * @return array ['success' => bool, 'msg' => string]
     */
    public static function truncateCategory(string $code, int $force = 0): array
    {
        $rows = \App\Repository\RepositoryFactory::cate()->find(array(), array('cid' => 1), 1, 100000);
        $total = is_array($rows) ? count($rows) : 0;
        if (0 === $total) {
            admin_audit_log('api_cate_truncate', array('code' => $code, 'result' => 'already_empty'));
            return array('success' => TRUE, 'msg' => '分类表本来就是空的，无需清空');
        }

        $vodRef = \App\Repository\RepositoryFactory::vod()->count();
        $artRef = \App\Repository\RepositoryFactory::art()->count();
        if (1 !== $force && ($vodRef > 0 || $artRef > 0)) {
            admin_audit_log('api_cate_truncate_denied', array(
                'code' => $code, 'cate' => $total, 'vod' => $vodRef, 'art' => $artRef,
            ));
            return array(
                'success' => FALSE,
                'msg' => '已拒绝执行：全站 ' . $total . ' 个栏目上挂着 ' . $vodRef . ' 个视频、' . $artRef
                    . ' 篇文章，清空后它们的分类归属会同时失效（前台详情页 500、播放/列表页 404）。'
                    . '如确需清空请在页面上按提示完成二次确认；只想调整个别栏目请改用「栏目管理」。'
            );
        }

        $backup = self::backupCategories($rows);
        if ('' === $backup) {
            admin_audit_log('api_cate_truncate_aborted', array(
                'code' => $code, 'cate' => $total, 'reason' => 'backup_failed',
            ));
            return array(
                'success' => FALSE,
                'msg' => '已中止执行：栏目备份文件写入失败。TRUNCATE 无法回滚，没有备份就不清空——请检查 runtime/backup/ 目录是否存在且 www 用户可写。'
            );
        }

        \App\Repository\RepositoryFactory::cate()->truncate();
        cate_list_cache_delete();
        admin_audit_log('api_cate_truncate', array(
            'code' => $code, 'cate' => $total, 'vod' => $vodRef, 'art' => $artRef, 'force' => $force, 'backup' => $backup,
        ));

        return array(
            'success' => TRUE,
            'msg' => '已清空 ' . $total . ' 个栏目。回滚文件 ' . $backup
                . '（服务器上执行：mysql -u用户 -p 数据库名 < ' . $backup . '  即可整表恢复）'
        );
    }

    /**
     * 把整张栏目表导出为可直接回灌的 SQL 备份文件，返回相对路径；任何一步失败返回 ''
     *
     * @param array $rows find() 取回的原始行（键即表列）
     */
    public static function backupCategories(array $rows): string
    {
        if (empty($rows)) return '';
        $dir = self::$backupDir !== NULL ? self::$backupDir : APP_PATH . 'runtime/backup/';
        if (!is_dir($dir) && !@mkdir($dir, 0755, TRUE)) return '';
        if (!is_dir($dir) || !is_writable($dir)) return '';

        $db = _SERVER('db');
        if (!is_object($db) || !method_exists($db, 'quote') || empty($db->tablepre)) return '';
        $table = $db->tablepre . 'cate';

        $first = current($rows);
        if (!is_array($first)) return '';
        $cols = array();
        foreach ($first as $k => $v) {
            if (preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', (string)$k)) $cols[] = (string)$k;
        }
        if (empty($cols)) return '';

        $ticks = array();
        foreach ($cols as $c) $ticks[] = '`' . $c . '`';
        $sql = array(
            '-- NiuNiuCms 栏目表自动备份（采集接口「清空分类」执行前生成）',
            '-- 生成时间: ' . date('Y-m-d H:i:s') . '    行数: ' . count($rows),
            '-- 恢复前若目标表已有新数据请先自行确认取舍；需要整表替换再手工执行下面这行：',
            '-- TRUNCATE TABLE `' . $table . '`;',
            'SET NAMES utf8mb4;',
        );

        $values = array();
        foreach ($rows as $row) {
            $vs = array();
            foreach ($cols as $c) {
                if (!array_key_exists($c, $row)) { $vs[] = 'NULL'; continue; }
                $q = $db->quote((string)$row[$c]);
                if (!is_string($q) || '' === $q) return '';   // 引用失败宁可中止
                $vs[] = $q;
            }
            $values[] = '(' . implode(',', $vs) . ')';
        }
        $sql[] = 'INSERT INTO `' . $table . '` (' . implode(',', $ticks) . ') VALUES' . "\n" . implode(",\n", $values) . ';';

        $name = 'cate_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.sql';
        $path = rtrim($dir, '/') . '/' . $name;
        $bytes = @file_put_contents($path, implode("\n", $sql) . "\n", LOCK_EX);
        if (!is_int($bytes) || $bytes < 1 || !is_file($path) || filesize($path) < 1) {
            @unlink($path);
            return '';
        }
        @chmod($path, 0640);
        self::rotateBackups($dir);
        return basename(rtrim($dir, '/')) . '/' . $name;
    }

    /**
     * 只保留最近 BACKUP_KEEP 份栏目备份（文件名含时间戳，名字序即时间序）
     */
    private static function rotateBackups(string $dir): void
    {
        $list = glob(rtrim($dir, '/') . '/cate_*.sql');
        if (!is_array($list) || count($list) <= self::BACKUP_KEEP) return;
        $old = array_slice($list, 0, count($list) - self::BACKUP_KEEP);
        foreach ($old as $f) @unlink($f);
    }

    /**
     * 导入默认分类
     * 
     * @param string $type 分类类型 (v, x, av, art)
     * @return array ['success' => bool, 'msg' => string, 'url' => string]
     */
    public static function importDefaultCategory(string $type): array
    {
        admin_audit_log('api_import', ['type' => 'into_' . $type]);
        $result = self::autoInto($type);
        
        $msgMap = [
            'v' => '导入影视分类成功',
            'x' => '导入X影视分类成功',
            'av' => '导入X资讯分类成功',
            'art' => '导入影视资讯分类成功',
        ];
        
        $urlMap = [
            'v' => 'api-list',
            'x' => 'api-list',
            'av' => 'api-art_list',
            'art' => 'api-art_list',
        ];
        
        $msg = $msgMap[$type] ?? '导入分类成功';
        $url = $urlMap[$type] ?? 'api-list';

        if (empty($result['success'])) {
            return [
                'success' => false,
                'msg' => $result['msg'] ?? '未取到默认分类模板',
                'url' => $url,
            ];
        }
        
        return [
            'success' => true, 
            'msg' => $msg,
            'url' => $url
        ];
    }

    /**
     * 一键绑定采集源：从 CloudOpsService::cloudPayload()（商店 api.php 顶层数组，自适应加密）
     * 按 $code 取采集 API 配置——键优先 api、兼容历史 apilist（原实现只读 apilist 恒 miss，已修）。
     * 未命中 ⇒ FALSE；命中则先按类型 autoInto 导入默认分类（'影视资源'→v，其余→x；宽松 == 比较），
     * 再把播放器条目写入 conf['vodplayer'] 并 kv_set('vodplayer') 持久化到 KV 运行时层
     * （历史上写物理 conf/conf.php 属失效写点，已废）。
     * ⚠ $arr 为外部商店数据：name/player 等未再转义即入库，出口消费点须按上下文转义（§二.4）。
     * @param string $code 采集源编码
     * @return array|false 成功返回该采集源配置行（$arr）；未命中返回 FALSE
     */
    /**
     * 从商店 API 拉取默认分类模板：读取 `index.php?api=cate&cate=<v|x|av|art>`，
     * 用于把出厂分类模板集中维护在 `.domian/config/cate*.php`。
     * 请求失败/商店未配置/响应结构不符时返回空数组。
     *
     * @param string $cate 分类模板键（v/x/av/art）
     * @return array
     */
    private static function remoteCategoryTree(string $cate): array
    {
        if (!in_array($cate, array('v', 'x', 'av', 'art'), TRUE)) return array();

        $conf = _SERVER('conf');
        $base = is_array($conf) && isset($conf['cloud_api']) ? rtrim(trim((string)$conf['cloud_api']), '/') : '';
        if ('' === $base) return array();

        $raw = https_request($base . '/index.php?api=cate&cate=' . rawurlencode($cate), '', '', 15, 0, 2 * 1048576);
        $json = xn_json_decode((string)$raw);
        $list = is_array($json) && isset($json['data']['list']) && is_array($json['data']['list']) ? $json['data']['list'] : array();
        return is_array($list) ? $list : array();
    }

    /**
     * 导入默认分类树：仅从商店 API 拉取 `.domian/config/cate*.php` 模板；拿到分类树后连同基础字段 $typearr
     * （type=1、nav_display=1、display=0 等）交给 ApiImportService::buildCategoryTree 落库。
     * 分类名在彼处做 htmlspecialchars 入库转义，查重原名/转义名双查；$typearr 引用传递，
     * model 覆盖跨分类累积——与 fastCat 同语义。
     * $model：art/av ⇒ 1（文章），其余（v/x）⇒ 0（影视）。
     * ⚠ `nncms` 仓库不再保留本地分类模板；商店未配置或模板接口不可达时 fail-closed 返回错误。
     * @param string $cate 分类模板键（v/x/av/art）
     * @return array ['success' => bool, 'msg' => string]
     */
    public static function autoInto(string $cate): array
    {
        $r = self::remoteCategoryTree($cate);
        if (empty($r)) {
            return array(
                'success' => FALSE,
                'msg' => '未取到商店默认分类模板，请检查 cloud_api 配置或商店 `api=cate` 接口',
            );
        }
        $model = ($cate == 'art' || $cate == 'av') ? 1 : 0;

        $typearr = array(
            'type' => 1,
            'model' => $model,
            'display' => 0,
            'nav_display' => 1,
            'seo_title' => '',
            'seo_key' => '',
            'seo_des' => '',
            'create_date' => time(),
            'alias' => '',
        );
        \App\Service\Collection\ApiImportService::buildCategoryTree($r, $typearr);
        return array('success' => TRUE, 'msg' => '');
    }
}
