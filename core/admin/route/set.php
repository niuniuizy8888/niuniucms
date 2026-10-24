<?php
!defined('DEBUG') and exit('Access Denied.');

// hook admin_set_start.php
FALSE === group_access($gid, 'manageset') and message(1, lang('user_group_insufficient_privilege'));
$action = param(1);
switch ($action) {
    case 'set':
        if ('GET' == $method) {
            extract(\App\Service\Admin\SetManageService::websitePage($conf, $config, $uid), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/set.html');
        } elseif ('POST' == $method) {
            $result = \App\Service\Admin\SetManageService::saveWebsite($config, $uid);
            message($result['code'], $result['message']);
        }
        break;
    case 'route':
        if ('GET' == $method) {
            extract(\App\Service\Admin\SetManageService::routePage($conf, $uid), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/set_route.html');
        } elseif ('POST' == $method) {
            $result = \App\Service\Admin\SetManageService::saveRoutes($uid);
            message($result['code'], $result['message']);
        }
        break;
    case 'player_add':
        if ('POST' == $method) {
            $result = \App\Service\Admin\SetManageService::playerAdd($uid);
            message($result['code'], $result['message']);
        }
        break;
    case 'player':
        if ('GET' == $method) {
            extract(\App\Service\Admin\SetManageService::playerPage($conf, $uid), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/set_player.html');
        } elseif ('POST' == $method) {
            $result = \App\Service\Admin\SetManageService::savePlayer($uid);
            message($result['code'], $result['message']);
        }
        break;
    case 'danmu':
        if ('GET' == $method) {
            extract(\App\Service\Admin\SetManageService::danmuPage($conf, $uid), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/set_danmu.html');
        } elseif ('POST' == $method) {
            $result = \App\Service\Admin\SetManageService::saveDanmu($uid);
            message($result['code'], $result['message']);
        }
        break;
    case 'op':
        if ('GET' == $method) {
            extract(\App\Service\Admin\SetManageService::opPage($conf), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/set_op.html');
        }
        break;
    case 'rw':
        if ('GET' == $method) {
            extract(\App\Service\Admin\SetManageService::rewritePage($conf, $uid), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/set_rw.html');
        } elseif ('POST' == $method) {
            $result = \App\Service\Admin\SetManageService::saveRewrite($uid);
            message($result['code'], $result['message']);
        }
        break;
    case 'authkey':
        // 安全审查第 2 轮：后台一键更换 auth_key——仅超级管理员组（gid=1），CSRF 场景令牌 + 二次确认
        1 !== intval($gid) and message(1, '仅超级管理员可以更换 auth_key');
        if ('GET' == $method) {
            extract(\App\Service\Admin\SetManageService::authKeyPage($uid), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/set_authkey.html');
        } elseif ('POST' == $method) {
            $result = \App\Service\Admin\SetManageService::rotateAuthKey($uid);
            message($result['code'], $result['message']);
        }
        break;
    case 'cache':
        if ('GET' == $method) {
            extract(\App\Service\Admin\SetManageService::cachePage($uid), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/set_cache.html');
        } elseif ('POST' == $method) {
            $result = \App\Service\Admin\SetManageService::clearCache($conf, $uid);
            message($result['code'], $result['message']);
        }
        break;
    case 'upgrade':
        // 上线前审计 AUTH-03：升级包覆盖全站代码——仅超级管理员（与 authkey 同门禁）
        1 !== intval($gid) and message(1, '仅超级管理员可以执行系统升级');
        if ('POST' == $method && 1 == admin_param_int('type')) {
            $result = \App\Service\Admin\SetManageService::upgrade($uid, $cloud_version, $conf['cache_path']);
            message($result['code'], $result['message']);
        } else {
            extract(\App\Service\Admin\SetManageService::upgradePage($uid, $cloud_version), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/set_upgrade.html');
        }
        break;
    case 'seo':
        if ('GET' == $method) {
            extract(\App\Service\Admin\SetManageService::seoPage($uid), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/set_seo.html');
        } elseif ('POST' == $method) {
            $result = \App\Service\Admin\SetManageService::saveSeo($conf, $uid);
            message($result['code'], $result['message']);
        }
        break;
    case 'rep':
        // 上线前审计 AUTH-03：全站地址替换影响播放源——仅超级管理员
        1 !== intval($gid) and message(1, '仅超级管理员可以执行地址替换');
        if ('GET' == $method) {
            extract(\App\Service\Admin\SetManageService::repPage(intval($uid)), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/set_rep.html');
        } elseif ('POST' == $method) {
            // do=submit（默认）：老域名 → 新域名 下发给商店（登记 + 触发 worker 全站推送）
            // do=pull：从商店拉取最近 30 天替换并**本地应用**（内网部署用；商店推送按 M4 拒私网目标）
            if ('pull' === admin_param_text('do', '', 10)) {
                $result = \App\Service\Admin\SetManageService::replacePull(intval($uid));
            } else {
                $result = \App\Service\Admin\SetManageService::replaceSubmit(intval($uid));
            }
            message($result['code'], $result['message']);
        }
        break;
    case 'rm':
        // 上线前审计 AUTH-03：清空全站数据——仅超级管理员 + 确认词
        1 !== intval($gid) and message(1, '仅超级管理员可以清空全站数据');
        if ('GET' == $method) {
            extract(\App\Service\Admin\SetManageService::clearDataPage($uid), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/set_rm.html');
        } elseif ('POST' == $method) {
            $result = \App\Service\Admin\SetManageService::clearData($conf, $uid);
            message($result['code'], $result['message']);
        }
        break;
    case 'domain':
        // 上线前审计 AUTH-03：站群域名配置影响 Host/站点隔离——仅超级管理员
        1 !== intval($gid) and message(1, '仅超级管理员可以管理站群域名');
        if ('GET' == $method) {
            extract(\App\Service\Admin\SetManageService::domainPage($uid), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/set_domain.html');
        } elseif ('POST' == $method) {
            $result = \App\Service\Admin\SetManageService::saveDomain($uid);
            message($result['code'], $result['message']);
        }
        break;
    case 'domain_add':
        1 !== intval($gid) and message(1, '仅超级管理员可以管理站群域名');
        if ('POST' == $method) {
            $result = \App\Service\Admin\SetManageService::addDomain($uid);
            message($result['code'], $result['message']);
        }
        break;
    case 'domain_del':
        1 !== intval($gid) and message(1, '仅超级管理员可以管理站群域名');
        if ('POST' == $method) {
            $result = \App\Service\Admin\SetManageService::deleteDomain($uid);
            message($result['code'], $result['message']);
        }
        break;
    case 'es':
        if ('GET' == $method) {
            extract(\App\Service\Admin\SetManageService::esPage($conf, $uid), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/set_es.html');
        } elseif ('POST' == $method) {
            $result = \App\Service\Admin\SetManageService::saveEs($uid);
            message($result['code'], $result['message']);
        }
        break;
    case 'es_on':
        if ('POST' != $method) message(-1, lang('illegal_operation'));
        $result = \App\Service\Admin\SetManageService::toggleEs($uid);
        message($result['code'], $result['message']);
        break;
    case 'escreate':
        if ('POST' != $method) message(-1, lang('illegal_operation'));
        $result = \App\Service\Admin\SetManageService::createEs($uid);
        message($result['code'], $result['message']);
        break;
    case 'esdel':
        if ('POST' != $method) message(-1, lang('illegal_operation'));
        $result = \App\Service\Admin\SetManageService::deleteEs($uid);
        message($result['code'], $result['message']);
        break;
    case 'es_bulk':
        if ('POST' != $method) message(-1, lang('illegal_operation'));
        $result = \App\Service\Admin\SetManageService::bulkEs($uid, _SERVER('db'));
        message($result['code'], $result['message']);
        break;
    case 'throttle':
        if ('GET' == $method) {
            extract(\App\Service\Admin\SetManageService::throttlePage($uid), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/set_throttle.html');
        } elseif ('POST' == $method) {
            $result = \App\Service\Admin\SetManageService::saveThrottle($uid);
            message($result['code'], $result['message']);
        }
        break;
    default:
        message(-1, lang('data_malformation'));
        break;
}
