<?php
namespace App\Service\Front;

class MemberService
{
    /**
     * 点赞/取消点赞写操作（route/member.php case 'like' POST；路由层已全局 user_login_check()）。
     * 前台会员写操作门禁 = user_bull_token_guard 场景 'member_action'（与后台 safe_token 同款会话指纹环，fail-closed）。
     * 幂等语义（审计修复在案）：已赞再点不重复计数；unlike 无记录时不减计数。
     * 所有分支经 nn_message() 输出 JSON 并 **exit**，本函数无正常返回路径。
     * @param int $uid 当前登录用户 uid
     */
    public static function toggleLike($uid)
    {
        $safe_token = param('safe_token');
        user_bull_token_guard($uid, 'member_action', $safe_token);
        $aid = intval(param('aid'));
        $type = param('type');
        $model = param('model');

        $model = '1' == $model ? 1 : 0;
        if ('like' == $type) {
            self::requireReadableContent($aid, $model);
            if (\App\Service\Content\LikeService::check($aid, $uid, $model)) nn_message(0, '已点赞');
            // add 撞唯一键失败（并发重复）时不再自增计数（审计修复）
            $r = \App\Service\Content\LikeService::add($aid, $uid, $model);
            $r and \App\Service\Content\LikeService::incrementCounter($aid, $model);
            nn_message(0, '点赞成功');
        } elseif ('unlike' == $type) {
            // 撤权后仍可取消自己的旧记录；仅实际删掉记录时减数，避免并发重复取消误减。
            if (\App\Service\Content\LikeService::remove($aid, $uid, $model)) {
                \App\Service\Content\LikeService::decrementCounter($aid, $model);
            }
            nn_message(3, '取消点赞成功');
        }

        nn_message(-1, lang('parameter_error'));
    }

    /**
     * 「我的点赞」列表装配：按可读内容关联计算总数和分页，再批量回表。
     * 只读，GET 分支专用。
     * @param int $uid 用户 uid
     * @param int|string $page 页码（调用点传 param('page',1)，字符串亦可）
     * @param int $limit 每页条数（route 固定 20）
     * @param int|string $model 1=文章、其余按 0=视频（'1'==宽松比较）
     * @return array{title:string, lfarrlis:array|null, total:int, pagination:array} 模板变量包
     */
    public static function buildLikeList($uid, $page, $limit, $model)
    {
        return self::buildRelationList($uid, $page, $limit, $model, 'like');
    }

    /**
     * 收藏/取消收藏写操作（route/member.php case 'favorite' POST，登录态 + user_bull_token_guard
     * 场景 'member_action' fail-closed）。与 toggleLike 同款幂等语义：重复收藏不计数、
     * 无记录取消不减数。所有分支经 nn_message() 输出 JSON 并 **exit**，无正常返回路径。
     * @param int $uid 当前登录用户 uid
     */
    public static function toggleFavorite($uid)
    {
        $safe_token = param('safe_token');
        user_bull_token_guard($uid, 'member_action', $safe_token);
        $aid = intval(param('aid'));
        $type = param('type');
        $model = param('model');

        $model = '1' == $model ? 1 : 0;
        if ('favorite' == $type) {
            self::requireReadableContent($aid, $model);
            if (\App\Service\Content\FavoriteService::check($aid, $uid, $model)) nn_message(0, '已收藏');
            // add 撞唯一键失败（并发重复）时不再自增计数（审计修复）
            $r = \App\Service\Content\FavoriteService::add($aid, $uid, $model);
            $r and \App\Service\Content\FavoriteService::incrementCounter($aid, $model);
            nn_message(0, '收藏成功');
        } elseif ('unfavorite' == $type) {
            // 不要求仍能阅读内容，用户可以清理撤权或删除前留下的收藏。
            if (\App\Service\Content\FavoriteService::remove($aid, $uid, $model)) {
                \App\Service\Content\FavoriteService::decrementCounter($aid, $model);
            }
            nn_message(3, '取消收藏成功');
        }

        nn_message(-1, lang('parameter_error'));
    }

    /**
     * 「我的收藏」列表装配：与 buildLikeList 同构，数据源换 FavoriteService；
     * 分页路由名用 art_favorites/vod_favorites（点赞版是 art_like/vod_like）。
     * @param int $uid 用户 uid
     * @param int|string $page 页码
     * @param int $limit 每页条数（route 固定 20）
     * @param int|string $model 1=文章、其余按 0=视频
     * @return array{title:string, lfarrlis:array|null, total:int, pagination:array} 模板变量包
     */
    public static function buildFavoriteList($uid, $page, $limit, $model)
    {
        return self::buildRelationList($uid, $page, $limit, $model, 'favorite');
    }

    /** 新增关系必须指向当前可读内容；直接读取最小列，避免旧内容缓存沿用已变更的分类。 */
    private static function requireReadableContent(int $id, int $model): void
    {
        global $gid;
        $content = $id > 0
            ? ($model === 1
                ? \App\Repository\RepositoryFactory::art()->findOne(['aid' => $id], [], ['aid', 'cid'])
                : \App\Repository\RepositoryFactory::vod()->findOne(['vid' => $id], [], ['vid', 'cid']))
            : null;
        if (!$content || !\App\Service\Content\CateService::accessUser((int)($content['cid'] ?? 0), (int)$gid, 'allowread')) {
            nn_message(-1, '内容不存在或无权访问');
        }
    }

    /** 两类内容及两种关系使用同一可见性、总数与分页口径，不在分页后剔除整页。 */
    private static function buildRelationList($uid, $page, $limit, $model, string $kind): array
    {
        $model = '1' == $model ? 1 : 0;
        $isArt = 1 == $model;
        $result = \App\Repository\MemberRelationRepository::page(
            $kind, (int)$uid, $model, (int)$page, (int)$limit,
            \App\Service\Content\ContentVisibilityService::categoryIds()
        );
        $ids = $result['ids'];
        $rows = $ids ? ($isArt
            ? \App\Service\Content\ArtService::findAsc($ids, count($ids))
            : \App\Service\Content\VideoService::findAsc($ids, count($ids), true)) : [];
        $lfarrlis = \App\Service\Content\ContentVisibilityService::filter((array)$rows);
        $isLike = $kind === 'like';
        $route = 'member-' . ($isArt ? 'art_' : 'vod_') . ($isLike ? 'like' : 'favorites');
        $pagination = self::buildPagination($result['total'], $result['page'], $result['limit'], $route);
        return array(
            'title' => ($isArt ? '文章' : '视频') . ($isLike ? '点赞' : '收藏'),
            'lfarrlis' => $lfarrlis,
            'total' => $result['total'],
            'pagination' => $pagination,
        );
    }

    /**
     * 会员中心分页条（上一页/页码窗口/下一页；审计修复：此前列表无分页数据）
     */
    private static function buildPagination(int $total, int $page, int $limit, string $route): array
    {
        $totalPage = max(1, (int)ceil($total / $limit));
        $page = max(1, min(intval($page), $totalPage));
        $list = array();
        if ($totalPage <= 1) return $list;
        $mk = function ($p) use ($route) {
            return url($route, array('page' => $p));
        };
        if ($page > 1) {
            $list[] = array('url' => $mk($page - 1), 'label' => '上一页', 'active' => false);
        }
        $start = max(1, $page - 2);
        $end = min($totalPage, $page + 2);
        if ($start > 1) {
            $list[] = array('url' => $mk(1), 'label' => '1', 'active' => false);
            if ($start > 2) $list[] = array('url' => '', 'label' => '…', 'active' => false);
        }
        for ($i = $start; $i <= $end; $i++) {
            $list[] = array('url' => $mk($i), 'label' => (string)$i, 'active' => $i == $page);
        }
        if ($end < $totalPage) {
            if ($end < $totalPage - 1) $list[] = array('url' => '', 'label' => '…', 'active' => false);
            $list[] = array('url' => $mk($totalPage), 'label' => (string)$totalPage, 'active' => false);
        }
        if ($page < $totalPage) {
            $list[] = array('url' => $mk($page + 1), 'label' => '下一页', 'active' => false);
        }
        return $list;
    }

    /**
     * 改密页装配（GET）：仅出标题 + 签发 'member_passwd' 场景 token（bull_token_set 登记进会话指纹环，
     * 与 handlePassword 的校验场景配对——不签发则改密必被 guard 拒）。
     * @param int $uid 用户 uid
     * @return array{title:string, safe_token:string|false} 用户不存在时 bull_token_set 返回 FALSE
     */
    public static function buildPasswordView($uid)
    {
        return array(
            'title' => '修改密码',
            'safe_token' => bull_token_set($uid, 'member_passwd'),
        );
    }

    /**
     * 会员改密（route/member.php case 'password' POST）：user_bull_token_guard('member_passwd')
     * fail-closed → 新密码非空 + is_password 强度校验（防空串散列锁死账号，修复注释在案）→
     * 两次一致 → password_verify_custom 验旧密（兼容存量 MD5+salt）→ bcrypt 写 UserService::update 并清 salt →
     * 成功后 session_regenerate_id(true) 轮换会话（VULN-005）。
     * 注意：密码取自 param() 默认 htmlspecialchars 转义（与注册/登录/后台同管线，全站一致口径）。
     * 所有分支经 message() 中断，无正常返回路径。
     * @param int $uid 用户 uid
     * @param array $user 当前用户行（路由层 $user，需含 password/salt 键）
     */
    public static function handlePassword($uid, $user)
    {
        $safe_token = param('safe_token');
        user_bull_token_guard($uid, 'member_passwd', $safe_token);
        $password_old = param('password_old');
        $password_new = param('password_new');
        $password_new_repeat = param('password_new_repeat');
        // 修复：空/弱新密码会 password_hash('') 入库后被 password_verify_custom 空值拒绝，账号永久锁死
        $password_new === '' and message('password', lang('please_input_password'));
        is_password($password_new, $err) or message('password', $err);
        $password_new_repeat != $password_new and message(-1, lang('repeat_password_incorrect'));
        password_verify_custom($password_old, $user['password'], (string)($user['salt'] ?? '')) or message(-1, lang('old_password_incorrect'));
        $password_new_hash = password_hash($password_new, PASSWORD_BCRYPT);
        FALSE === \App\Service\Account\UserService::update($uid, array('password' => $password_new_hash, 'salt' => '')) and message(-1, lang('password_modify_failed'));
        // 上线前审计 AUTH-02：清 remember-me Cookie；其它会话已在 UserService::update 内按 uid 销毁
        \App\Service\Account\UserAuthService::tokenClear();
        // 上线前安全审计修复（VULN-005）：改密成功后轮换会话 ID（与 UserService::handlePasswd 同口径）
        session_regenerate_id(true);
        message(0, lang('password_modify_successfully'));
    }
}
