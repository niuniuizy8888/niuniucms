<?php
!defined('DEBUG') and exit('Access Denied.');


// hook route_member_start.php
user_login_check();
$action = param(1, 'index');
$header['title'] = '会员中心';
switch ($action) {
    case 'like':
        if ('GET' == $method) {
            $header['title'] = '我的点赞';
            include _include(\App\Service\System\TemplateService::userHtmlFile('like.html'));
        } elseif ('POST' == $method) {
            \App\Service\Front\MemberService::toggleLike($uid);
        }
        break;
    case 'art_like':
        if ('GET' == $method) {
            $page = param('page', 1);
            $limit = 20;
            $data = \App\Service\Front\MemberService::buildLikeList($uid, $page, $limit, '1');
            $pagination = $data['pagination'];
            $header['title'] = $data['title'];
            $lfarrlis = $data['lfarrlis'];
            include _include(\App\Service\System\TemplateService::userHtmlFile('like.html'));
        }
        break;
    case 'vod_like':
        if ('GET' == $method) {
            $page = param('page', 1);
            $limit = 20;
            $data = \App\Service\Front\MemberService::buildLikeList($uid, $page, $limit, '0');
            $pagination = $data['pagination'];
            $header['title'] = $data['title'];
            $lfarrlis = $data['lfarrlis'];
            include _include(\App\Service\System\TemplateService::userHtmlFile('like.html'));
        }
        break;
    case 'favorite':
        if ('GET' == $method) {
            $header['title'] = '我的收藏';
            include _include(\App\Service\System\TemplateService::userHtmlFile('favorite.html'));
        } elseif ('POST' == $method) {
            \App\Service\Front\MemberService::toggleFavorite($uid);
        }
        break;
    case 'art_favorites':
        if ('GET' == $method) {
            $page = param('page', 1);
            $limit = 20;
            $data = \App\Service\Front\MemberService::buildFavoriteList($uid, $page, $limit, '1');
            $pagination = $data['pagination'];
            $header['title'] = $data['title'];
            $lfarrlis = $data['lfarrlis'];
            include _include(\App\Service\System\TemplateService::userHtmlFile('favorite.html'));
        }
        break;
    case 'vod_favorites':
        if ('GET' == $method) {
            $page = param('page', 1);
            $limit = 20;
            $data = \App\Service\Front\MemberService::buildFavoriteList($uid, $page, $limit, '0');
            $pagination = $data['pagination'];
            $header['title'] = $data['title'];
            $lfarrlis = $data['lfarrlis'];
            include _include(\App\Service\System\TemplateService::userHtmlFile('favorite.html'));
        }
        break;
    case 'password':
        if ('GET' == $method) {
            $data = \App\Service\Front\MemberService::buildPasswordView($uid);
            $header['title'] = $data['title'];
            $safe_token = $data['safe_token'];
            include _include(\App\Service\System\TemplateService::userHtmlFile('password.html'));
        } elseif ('POST' == $method) {
            \App\Service\Front\MemberService::handlePassword($uid, $user);
        }

        break;
    default:
        $header['title'] = '会员中心';
        include _include(\App\Service\System\TemplateService::userHtmlFile('index.html'));
        break;
}

// hook route_member_end.php

