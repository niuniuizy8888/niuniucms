<?php
!defined('DEBUG') and exit('Access Denied.');


// hook route_user_start.php
$passwd_url = $user_passwd = url('user-passwd');
$action = param(1);
switch ($action) {
    case 'login':
        $uid and http_location($conf['path']);
        if ('GET' == $method) {
            $data = \App\Service\Front\UserService::buildLoginView($conf, isset($safe_token) ? $safe_token : '');
            $header['title'] = $data['header']['title'];
            $safe_token = $data['safe_token'];
            $referer = $data['referer'];
            $extra = $data['extra'];
            $form_action = $data['form_action'];
            include _file(view_load('user_login'));
        } elseif ('POST' == $method) {
            \App\Service\Front\UserService::handleLogin($conf, $longip);
        }
        break;
    case 'create':
        $uid and http_location($conf['path']); 
        empty($conf['user_create_on']) and message(-1, lang('user_create_not_on'));
        if ('GET' == $method) {
            $data = \App\Service\Front\UserService::buildCreateView($conf);
            $header['title'] = $data['header']['title'];
            $referer = $data['referer'];
            $safe_token = $data['safe_token'];
            $extra = $data['extra'];
            $form_action = $data['form_action'];
            if ('1' == _GET('ajax')) {
                $apilist['header'] = $header;
                $apilist['safe_token'] = $safe_token;
                $apilist['referer'] = $referer;
                $apilist['action'] = $form_action;
                $conf['api_on'] ? message(0, $apilist) : (http_response_code(403) and message(-1, lang('closed')));
            } else {
                include _include(\App\Service\System\TemplateService::userHtmlFile('user_create.html'));
            }
        } else if ('POST' == $method) {
            \App\Service\Front\UserService::handleCreate($conf, $longip, $time);
        }
        break;
    case 'logout':
        \App\Service\Front\UserService::handleLogout($uid);
        break;
    case 'passwd':
        empty($uid) and http_location(url('user-login'));
        if ('GET' == $method) {
            $data = \App\Service\Front\UserService::buildPasswdView($uid);
            $safe_token = $data['safe_token'];
            $nncms['safe_token'] = $safe_token;
            include _file(view_load('user_passwd'));
        } elseif ('POST' == $method) {
            \App\Service\Front\UserService::handlePasswd($uid, $user);
        }
        break;
    
    default:
        // 未知 action → 404（此前落首页 200；审计修复）
        http_response_code(404);
        include _include(APP_PATH . '404.html');
        break;
}

// hook route_user_end.php

