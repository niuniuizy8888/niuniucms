<?php
!defined('DEBUG') and exit('Access Denied.');

// hook admin_api_start.php
FALSE === group_access($gid, 'manageapi') and message(1, lang('user_group_insufficient_privilege'));
$action = param(1, 'list');
$header['title'] = lang('api_page');
switch ($action) {
    case 'import_sources':
        // B3：从商店一键导入资源站（明文地址；code 冲突跳过）
        if ('POST' == $method) {
            admin_require_bull_token($uid, 'admin_api_set');
            $conf = _SERVER('conf');
            $base = rtrim(trim(isset($conf['cloud_api']) ? (string)$conf['cloud_api'] : ''), '/');
            if ('' === $base) message(1, '未配置商店地址（config: cloud_api）');
            $arr = xn_json_decode((string)https_request($base . '/index.php?api=sources', '', '', 30, 0, 8 * 1048576));
            $list = (is_array($arr) && isset($arr['data']['list']) && is_array($arr['data']['list'])) ? $arr['data']['list'] : array();
            $n = 0;
            foreach ($list as $s) {
                $code = preg_replace('#[^A-Za-z0-9_-]#', '', (string)($s['code'] ?? ''));
                // ：apiinfo 全字段契约——api=采集地址（旧数据回退 url）、player=播放器解析地址
                $api = trim((string)($s['api'] ?? ''));
                if ('' === $api) $api = trim((string)($s['url'] ?? ''));
                $name = (string)($s['name'] ?? $code);
                $play = trim((string)($s['player'] ?? ''));
                if ('' === $code || '' === $api) continue;
                $r = \App\Service\Collection\ApiSourceService::set($code, $api, $name, $play);
                if (!empty($r['success'])) $n++;
            }
            admin_audit_log('api_import_sources', array('total' => count($list), 'imported' => $n));
            message(0, jump("商店资源站导入完成：{$n} 个", url('api-list', array(), TRUE), 2));
        }
        break;
    case 'store_replacements':
        // B3：查看商店最近 30 天域名替换记录（应用动作待 vod 字段对齐后开放）
        if ('GET' == $method) {
            $conf = _SERVER('conf');
            $base = rtrim(trim(isset($conf['cloud_api']) ? (string)$conf['cloud_api'] : ''), '/');
            $rows = array();
            $err = '';
            if ('' === $base) $err = '未配置商店地址（config: cloud_api）';
            else {
                $arr = xn_json_decode((string)https_request($base . '/index.php?api=replacements', '', '', 30, 0, 8 * 1048576));
                $rows = (is_array($arr) && isset($arr['data']['list']) && is_array($arr['data']['list'])) ? $arr['data']['list'] : array();
            }
            // ⚠ 口径澄清（真机取证）：商店有**两个**替换记录端点——
            //   ① `?macapi` 的 replist：**全量历史**（实测 55 条，跨 2024-03 ~ 2026-05），后台「地址替换」页用的就是它；
            //   ② `index.php?api=replacements`：**固定 30 天窗口**（响应自带 `window_days:30`，近期可能为 0 条）。
            //   旧版本页只渲染 ②，于是"只看得到最近 30 天"，而真正做过替换的资源站往往在窗口之外 ⇒ 看起来"没有记录"。
            //   现在两段都给出：②（窗口内）+ ①（全量），并各自标明条数与时间跨度。
            $all = \App\Service\System\CloudOpsService::niuniuzsCodes(TRUE);
            $allrows = (is_array($all) && isset($all['replist']) && is_array($all['replist'])) ? $all['replist'] : array();
            $span = function (array $list) {
                $ts = array();
                foreach ($list as $x) { if (!empty($x['addtime'])) $ts[] = (string)$x['addtime']; }
                sort($ts);
                return $ts ? (substr($ts[0], 0, 10) . ' ~ ' . substr(end($ts), 0, 10)) : '';
            };
            echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>商店替换记录</title></head><body style="font-family:system-ui;padding:20px">';
            // 发布前检查修复（A-1）：h() 全仓无定义（唯一转义助手是 xn_h）——原代码渲染必 Fatal
            if ('' !== $err) echo '<p style="color:#b91c1c">' . xn_h($err) . '</p>';
            echo '<h2>商店域名替换记录</h2>';
            echo '<h3 style="margin-bottom:4px">① 商店 30 天窗口（api=replacements）：' . count($rows) . ' 条</h3>';
            if (!count($rows)) echo '<p style="color:#6b7280">该窗口内无记录（窗口固定 30 天，做过替换的资源站通常早于窗口）。</p>';
            else {
                echo '<table border="1" cellpadding="6" style="border-collapse:collapse"><tr><th>资源站</th><th>类型</th><th>老域名</th><th>新域名</th><th>时间</th></tr>';
                foreach ($rows as $r) {
                    echo '<tr><td>' . xn_h($r['source_name'] ?? '') . '</td><td>' . ('pic' === ($r['type'] ?? '') ? '图片' : '播放') . '</td><td>' . xn_h($r['old_domain'] ?? '') . '</td><td>' . xn_h($r['new_domain'] ?? '') . '</td><td>' . xn_h(date('Y-m-d H:i', (int)($r['created_at'] ?? 0))) . '</td></tr>';
                }
                echo '</table>';
            }
            echo '<h3 style="margin:18px 0 4px">② 全量历史（商店 ?macapi 同步，后台「地址替换」页同源）：' . count($allrows) . ' 条'
                . ($span($allrows) !== '' ? '，跨度 ' . xn_h($span($allrows)) : '') . '</h3>';
            if (!count($allrows)) echo '<p style="color:#6b7280">未取到全量记录：商店暂不可达或尚未同步（看 <code>runtime/log 下的 store_error.php</code>）。</p>';
            else {
                echo '<table border="1" cellpadding="6" style="border-collapse:collapse"><tr><th>老域名</th><th>新域名</th><th>类型</th><th>备注/站点</th><th>时间</th></tr>';
                foreach ($allrows as $r) {
                    echo '<tr><td>' . xn_h($r['old'] ?? '') . '</td><td>' . xn_h($r['new'] ?? '') . '</td><td>' . xn_h($r['type'] ?? '') . '</td><td>' . xn_h($r['sitename'] ?? '') . '</td><td>' . xn_h($r['addtime'] ?? '') . '</td></tr>';
                }
                echo '</table>';
            }
            echo '<p><a href="' . xn_h(url('set-rep', array(), TRUE)) . '">前往后台「地址替换」</a> · <a href="' . xn_h(url('api-list', array(), TRUE)) . '">返回采集列表</a></p></body></html>';
            exit;
        }
        break;
    case 'rsync':
        if ('GET' == $method) {
            // 副作用动作（start/stop/restart）校验 CSRF token；查看类（see/seeall）免校验（审计修复）
            in_array(param('type'), array('start', 'stop', 'restart'), TRUE) and admin_require_bull_token($uid, 'admin_api_rsync');
            $result = \App\Service\Collection\ApiExecutionService::rsync(param('type'), param('code'));
            if (($result['mode'] ?? '') === 'redirect') {
                message(0, jump($result['msg'], url('api-list', array(), TRUE), 3));
            }
            if (($result['mode'] ?? '') === 'view') {
                $res = $result['res'];
                include _include(ADMIN_PATH . 'html/api_rsync.html');
            }
        }
        break;
    case 'api':
        if ('GET' == $method) {
            admin_require_bull_token($uid, 'admin_api_exec');
            $p = intval(param('p', 0));
            // 连采页数（1..10）：一次请求内顺序处理多页，省掉每页固定 3s 跳转延迟与整页加载。
            // 真机实测单页服务端 ~1.1s（远端取页占 87%），旧行为 4.1s/页 ⇒ 连采 5 页约 6.8s/5 页。
            $pages = intval(param('pages', 0));
            $pages < 1 and $pages = 1;
            $pages > 10 and $pages = 10;
            $pages > 1 and set_time_limit(90);   // 连采预算 20s，留足单页超时余量
            $result = \App\Service\Collection\ApiExecutionService::videoApi(
                param('type', 'day'),
                param('api'),
                param('code'),
                $p,
                intval($uid),
                $pages
            );
            !empty($result['error']) and message(-1, $result['msg']);
            !empty($result['done']) and message(0, jump($result['msg'], url($result['success_url'], array(), TRUE), 1));
            $resstr = $result['resstr'];
            $jump = $result['jump'];
            include _include(ADMIN_PATH . 'html/api_res.html');
        }
        break;
    case 'cate':
        if ('GET' == $method) {
            $res = \App\Service\Collection\ApiViewService::cateView(param('code', ''), intval($uid), $conf);
            admin_json_out($res);
        }  
        if ($method === 'POST') {
            admin_require_bull_token($uid, 'admin_api_cate');
            $code = admin_param_text('code', '', 32);
            $binstatus = admin_param_text('binstatus', '', 10);
            $name = admin_param_text('type_name', '', 255) ?: admin_param_text('name', '', 255);
            $local_id = admin_param_int('local_id', 0);

            if (empty($code) || empty($binstatus) || empty($name)) {
                admin_json_out(['success' => false, 'msg' => '参数不完整']);
            }

            if ($binstatus === 'bind') {
                $result = \App\Service\Collection\ApiBindService::bind($code, $name, $local_id);
            } elseif ($binstatus === 'unbind') {
                $result = \App\Service\Collection\ApiBindService::unbind($code, $name);
            } else {
                $result = ['success' => false, 'msg' => '未知的操作类型'];
            }

            admin_json_out($result);
        }
        break;


    case 'fastapi':
         if ('GET' == $method) {
            extract(\App\Service\Collection\ApiViewService::fastApiPage(intval($uid)), EXTR_SKIP); // S-4
            include _include(ADMIN_PATH . 'html/api_fastapi.html');
        }
        break;
    case 'fastdb':
            if ('POST' !== $method) {
                message(1, lang('illegal_operation'));
            }
            admin_require_bull_token($uid, 'admin_api_fastdb');
            $code = admin_param_text('code', '', 64);
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $code)) {
                message(-1, lang('data_malformation'));
            }
            $limit = admin_param_int('limit', 5000, 1, 50000);
            $stage = admin_param_text('stage', 'preview', 16);
            $page = admin_param_int('p', 1, 1);
            $batch_id = admin_param_int('batch_id', 0, 0);
            $update_mode = admin_param_text('update_mode', 'skip', 16);
            $update_scope = admin_param_text('update_scope', 'all', 16);
            if (!in_array($update_mode, array('skip', 'update'), TRUE)) {
                $update_mode = 'skip';
            }
            if (!array_key_exists($update_scope, \App\Service\Collection\ApiImportService::fastDbUpdateScopeOptions())) {
                $update_scope = 'all';
            }

            if ('preview' === $stage) {
                extract(\App\Service\Collection\ApiViewService::fastDbStage('preview', $code, $update_scope, intval($uid)), EXTR_SKIP);
                include _include(ADMIN_PATH . 'html/api_fast_preview.html');
            } elseif ('prepare' === $stage) {
                extract(\App\Service\Collection\ApiViewService::fastDbStage('prepare', $code, $update_scope, intval($uid), $limit, $update_mode), EXTR_SKIP);
                include _include(ADMIN_PATH . 'html/api_fast_res.html');
            } elseif ('import' === $stage) {
                $importData = \App\Service\Collection\ApiViewService::fastDbStage('import', $code, $update_scope, intval($uid), $limit, $update_mode, $page, $batch_id);
                if ($importData['error']) {
                    message(-1, $importData['msg']);
                }
                if ($importData['done']) {
                    message(0, jump($importData['msg'], url('api-batch', array('batch_id' => $batch_id), TRUE), 3));
                }
                extract($importData['view'], EXTR_SKIP);
                include _include(ADMIN_PATH . 'html/api_fast_res.html');
            } else {
                message(-1, lang('parameter_error'));
            }
        break;
    case 'batch':
        if ('GET' == $method) {
            $batch_export = admin_param_text('export', '', 16);
            $params = array(
                'batch_id' => admin_param_int('batch_id', 0, 0),
                'page' => admin_param_int('page', 1, 1),
                'item_page' => admin_param_int('item_page', 1, 1),
                'item_pagesize' => admin_param_int('item_pagesize', 20, 10, 200),
                'action_type' => admin_param_text('action_type', 'all', 16),
                'field_group' => admin_param_text('field_group', 'all', 16),
                'keyword' => admin_param_text('keyword', '', 100),
                'risk_level' => admin_param_text('risk_level', 'all', 16),
                'batch_sort' => admin_param_text('batch_sort', 'latest', 16)
            );

            if (!empty($params['batch_id']) && $batch_export === 'csv') {
                $batch_filters = array(
                    'action_type' => $params['action_type'],
                    'field_group' => $params['field_group'],
                    'keyword' => $params['keyword'],
                );
                \App\Service\Collection\ApiBatchService::exportCsv($params['batch_id'], $batch_filters);
            }
            extract(\App\Service\Collection\ApiBatchService::page($params, intval($uid)), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/api_batch.html');
        }
        break;
    case 'rollback':
        if ('POST' !== $method) {
            message(1, lang('illegal_operation'));
        }
        admin_require_bull_token($uid, 'admin_api_batch_rollback');
        
        $batch_id = admin_param_int('batch_id', 0, 1);
        $result = \App\Service\Collection\ApiBatchService::rollback($batch_id);
        
        if (intval($result['code']) !== 0) {
            message(-1, $result['message']);
        }
        message(0, jump('批次回滚完成，已删除 ' . intval($result['rollback_num'] ?? 0) . ' 条内容', url('api-batch', array('batch_id' => $batch_id), TRUE), 3));
        break;

    case 'list':
        if ('GET' == $method) {
            extract(\App\Service\Collection\ApiViewService::listPage(intval($uid)), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/api_list.html');
        }
        break;
    case 'art_list':
        if ('GET' == $method) {
            extract(\App\Service\Collection\ApiViewService::artListPage(intval($uid)), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/api_art_list.html');
        }
        break;
    case 'auto':
        if ('POST' == $method) {
            admin_require_bull_token($uid, 'admin_api_auto');
            $api = param('api');
            $code = param('code');
            if (!isset($conf['api'][$code]['api'])) {
                message(0, jump('请先设置采集源', url('api-list', array(), TRUE), 3));
            }
            $result = \App\Service\Collection\ApiBindService::autoBindAll($code, $api);
            message(0, jump($result['msg'], url('api-list', array(), TRUE), 3));
        }
        break;
    case 'newcreate':
         if ('POST' == $method) {
            admin_require_bull_token($uid, 'admin_api_newcreate');
            $result = \App\Service\Admin\CategoryManageService::createAjax(array(
                'code' => param('code', ''),
                'type_name' => param('type_name'),
                'seo_des' => param('seo_des', '', FALSE),
                'nav_display' => param('nav_display', 0),
                'model' => param('model', 0),
                'seo_title' => param('seo_title', '', FALSE),
                'seo_key' => param('seo_key', '', FALSE),
                'category' => param('category', 0),
                'fup' => _POST('fup', 0),
                'display' => param('display', 0),
            ));
            admin_json_out($result);
        }
        break;
    case 'create':
        if ('POST' == $method) {
            admin_require_bull_token($uid, 'admin_api_create');
            $result = \App\Service\Admin\CategoryManageService::createVideo(array(
                'api' => admin_param_text('api', '', 2048),
                'code' => admin_param_text('code', '', 32),
                'name' => admin_param_text('name', '', 255),
                'seo_des' => param('seo_des', '', FALSE),
                'nav_display' => param('nav_display', 0),
                'model' => param('model', 0),
                'seo_title' => param('seo_title', '', FALSE),
                'seo_key' => param('seo_key', '', FALSE),
                'category' => param('category', 0),
                'fup' => _POST('fup', 0),
                'display' => param('display', 0),
            ));
            message(0, jump('分类创建成功', url('api-list', array(), TRUE), 3));
        }
        break;
    case 'set':
         if ('POST' == $method) {
            admin_require_bull_token($uid, 'admin_api_set');
            $api = param('api');
            $code = param('code');
            $name = param('name');
            $play = param('play');
            $result = \App\Service\Collection\ApiSourceService::set($code, $api, $name, $play);
            if (!$result['success']) {
                message(1, $result['msg']);
            }
            message(0, jump($result['msg'], url('api-list', array(), TRUE), 1));
        }
        break;
    case 'set_art':
        if ('POST' == $method) {
            admin_require_bull_token($uid, 'admin_api_set_art');
            $api = param('api');
            $code = param('code');
            $name = param('name');
            $result = \App\Service\Collection\ApiArtService::set($code, $api, $name);
            message(0, jump($result['msg'], url('api-art_list', array(), TRUE), 3));
        }
        break;

    case 'setdel':
        if ('POST' == $method) {
            admin_require_bull_token($uid, 'admin_api_setdel');
            $code = param('code');
            $result = \App\Service\Collection\ApiSourceService::delete($code);
            message(0, jump($result['msg'], url('api-list', array(), TRUE), 3));
        }
        break;
    case 'del':
        if ('POST' !== $method) {
            message(1, lang('illegal_operation'));
        }
        admin_require_bull_token($uid, 'admin_api_catedel');
        $code = param('code');
        // 破坏性操作（TRUNCATE 全站栏目表）：必须带 force=1（前端明示破坏范围并二次确认后才会发），
        // 且服务层执行前会自动备份栏目表——误点致前台大面积 404/500 的加固
        $force = admin_param_int('force', 0, 0, 1);
        $result = \App\Service\Collection\ApiMiscService::truncateCategory($code, $force);
        empty($result['success']) and message(1, $result['msg']);
        message(0, jump($result['msg'], url('api-list', array(), TRUE), 3));
        break;
    case 'intov':
    case 'intox':
    case 'into_av':
    case 'into_art':
        if ('POST' !== $method) { message(1, lang('illegal_operation')); }
        admin_require_bull_token($uid, 'admin_api_import');
        
        $type = str_replace('into_', '', $action);
        if ($action === 'intov' || $action === 'intox') {
            $type = str_replace('into', '', $action);
        }
        
        $result = \App\Service\Collection\ApiMiscService::importDefaultCategory($type);
        empty($result['success']) and message(1, $result['msg']);
        message(0, jump($result['msg'], url($result['url'], array(), TRUE), 3));
        break;
    case 'art_auto':
        if ('POST' == $method) {
            admin_require_bull_token($uid, 'admin_api_art_auto');
            $api = param('api');
            $code = param('code');
            if (!isset($conf['api'][$code]['api'])) {
                message(0, jump('请先设置采集源', url('api-art_list', array(), TRUE), 3));
            }
            $result = \App\Service\Collection\ApiArtService::autoBindAll($code, $api);
            message(0, jump($result['msg'], url('api-art_list', array(), TRUE), 3));
        }
        break;
    case 'art_create':
        if ('POST' == $method) {
            admin_require_bull_token($uid, 'admin_api_art_create');
            $result = \App\Service\Admin\CategoryManageService::createArt(array(
                'api' => param('api'),
                'code' => param('code'),
                'name' => param('name'),
                'seo_des' => param('seo_des', '', FALSE),
                'nav_display' => param('nav_display', 0),
                'model' => param('model', 0),
                'seo_title' => param('seo_title', '', FALSE),
                'seo_key' => param('seo_key', '', FALSE),
                'category' => param('category', 0),
                'fup' => _POST('fup', 0),
                'display' => param('display', 0),
            ));
            message(0, jump('分类创建成功', url('api-art_list', array(), TRUE), 3));
        }
        break;
    case 'art_del':
        if ('POST' == $method) {
            admin_require_bull_token($uid, 'admin_api_artdel');
            $code = param('code');
            $result = \App\Service\Collection\ApiArtService::delete($code);
            message(0, jump($result['msg'], url('api-art_list', array(), TRUE), 3));
        }
        break;
    case 'art_api':
        if ('GET' == $method) {
            admin_require_bull_token($uid, 'admin_api_exec');
            $p = intval(param('p', 0));
            $pages = intval(param('pages', 0));       // 图文采集同样支持连采（1..10）
            $pages < 1 and $pages = 1;
            $pages > 10 and $pages = 10;
            $pages > 1 and set_time_limit(90);   // 连采预算 20s，留足单页超时余量
            $result = \App\Service\Collection\ApiExecutionService::artApi(
                param('type', 'day'),
                param('api'),
                param('code'),
                $p,
                intval($uid),
                $pages
            );
            !empty($result['error']) and message(-1, $result['msg']);
            !empty($result['done']) and message(0, jump($result['msg'], url($result['success_url'], array(), TRUE), 1));
            $resstr = $result['resstr'];
            $jump = $result['jump'];
            include _include(ADMIN_PATH . 'html/api_res.html');
        }
        break;
    default:
        message(-1, lang('data_malformation'));
        break;
}
