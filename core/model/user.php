<?php
// @deprecated 兼容壳 — 底层函数转发到 RepositoryFactory，业务函数转发到 UserService / UserAuthService / GroupService。新代码禁止调用。
// hook model_user_start.php
/**
 * @deprecated 兼容壳（文件头禁令，新代码禁止调用）：转发 RepositoryFactory::user()->insert($arr, $d)，
 * 走 QueryBuilder 真·预编译路径单行写 user 表；列名经 safeColumns 白名单**非法键静默剔除**，
 * 空数组直接短路。
 * ⚠ 凭据口径（红线 §二.9）：本壳**不做任何散列装配**，password 列必须由调用方先经
 *   password_hash_custom()（bcrypt）算好再传；把明文或 md5 直灌进来等于造一个不可用/易破的账号。
 * ⚠ user 表不在内容页缓存白名单里 ⇒ 本壳也不失效任何用户/会话缓存；
 *   注册、改密、改组一类业务请走 UserService / UserAuthService（它们负责缓存失效与会话联动）。
 * @param array $arr 列名 => 值 的待插入行（password 须已是 bcrypt 散列）
 * @param object|null $d 连接/从库选择器（NULL = 全局 $_SERVER['db']）
 * @return int|false 自增 uid（无自增或语句失败为 0）；空入参 = FALSE
 */
function user__create($arr, $d = NULL)
{
    // hook model_user_user__create_start.php

    $r = \App\Repository\RepositoryFactory::user()->insert($arr, $d);
    // hook model_user_user__create_end.php
    return $r;
}
/**
 * @deprecated 兼容壳 — 按 uid 更新 user 表一行，转发 RepositoryFactory::user()->update()。
 * @param mixed       $uid    目标行主键 uid（内部组装为 array('uid' => $uid) 条件）
 * @param array       $update 列名=>值 更新集（列名以 + 结尾走原子自增表达式）
 * @param object|null $d      连接/从库选择器，NULL=默认主连接
 * @return int|FALSE  影响行数；入参为空时 FALSE
 */
function user__update($uid, $update, $d = NULL)
{
    // hook model_user_user__update_start.php

    $r = \App\Repository\RepositoryFactory::user()->update(array('uid' => $uid), $update, $d);
    // hook model_user_user__update_end.php
    return $r;
}
/**
 * @deprecated 兼容壳 — 按条件读单行用户，转发 RepositoryFactory::user()->findOne()。
 * @param array       $cond    查询条件（列=>值；值传数组走 IN）
 * @param array       $orderby 排序（列=>1 升序 / -1 降序）
 * @param array       $col     列清单，空数组 = SELECT *（会被 sql_star_select 留痕）
 * @param object|null $d       连接/从库选择器
 * @return array|null 行数组；未命中或查询失败为 NULL（非 FALSE）
 */
function user__read($cond = array(), $orderby = array(), $col = array(), $d = NULL)
{
    // hook model_user_user__read_start.php

    $user = \App\Repository\RepositoryFactory::user()->findOne($cond, $orderby, $col, $d);
    // hook model_user_user__read_end.php
    return $user;
}
/**
 * @deprecated 兼容壳 — 分页查用户列表，转发 RepositoryFactory::user()->find()。
 * @param array       $cond     查询条件
 * @param array       $orderby  排序
 * @param int         $page     页码（从 1 起）
 * @param int         $pagesize 每页条数
 * @param string      $key      结果数组的键列，默认 uid
 * @param array       $col      列清单，空数组 = SELECT *
 * @param object|null $d        连接/从库选择器
 * @return array 以 $key 列值为键的行列表；无结果=空数组
 */
function user__find($cond = array(), $orderby = array(), $page = 1, $pagesize = 20, $key = 'uid', $col = array(), $d = NULL)
{
    // hook model_user_user__find_start.php

    $arr = \App\Repository\RepositoryFactory::user()->find($cond, $orderby, $page, $pagesize, $key, $col, $d);
    // hook model_user_user__find_end.php
    return $arr;
}
/**
 * @deprecated 兼容壳 — 按 uid 删除用户行，转发 RepositoryFactory::user()->delete()。
 * @param mixed       $uid 目标行主键
 * @param object|null $d   连接/从库选择器
 * @return int|FALSE 删除的影响行数（条件恒非空，正常不会返回 FALSE）
 */
function user__delete($uid, $d = NULL)
{
    // hook model_user_user__delete_start.php

    $r = \App\Repository\RepositoryFactory::user()->delete(array('uid' => $uid), $d);
    // hook model_user_user__delete_end.php
    return $r;
}
/**
 * @deprecated 兼容壳 — 统计用户数，转发 RepositoryFactory::user()->count()。
 * @param array       $cond 查询条件，空数组=全表
 * @param object|null $d    连接/从库选择器
 * @return int 命中行数
 */
function user_count($cond = array(), $d = NULL)
{
    // hook model_user_user_count_start.php

    $n = \App\Repository\RepositoryFactory::user()->count($cond, $d);
    // hook model_user_user_count_end.php
    return $n;
}
/**
 * @deprecated 兼容壳 — 批量插入用户行，转发 RepositoryFactory::user()->bigInsert()（多行 VALUES，全参数化）。
 * @param array       $arr 行列表 array(array(列=>值, ...), ...)
 * @param object|null $d   连接/从库选择器
 * @return int|FALSE 影响行数；$arr 为空时 FALSE
 */
/**
 * @deprecated 兼容壳 — 批量更新用户行，转发 RepositoryFactory::user()->bigUpdate()（按主键 CASE WHEN）。
 * @param array       $cond   条件数组；底层只取第一个键作主键列名（此壳场景下应传 array('uid' => ...)）
 * @param array       $update 以主键值为键的更新集 array(uid => array(列=>值, ...))
 * @param object|null $d      连接/从库选择器
 * @return int|FALSE 影响行数；入参为空时 FALSE
 */
/**
 * @deprecated 兼容壳 — 新增用户组行，转发 RepositoryFactory::group()->create()（等价 insert；不清 grouplist 缓存，业务层请用 group_create()）。
 * @param array $arr 列名=>值 的整行数据
 * @return int|FALSE 自增主键 gid；$arr 为空时 FALSE
 */
function group__create($arr)
{
    // hook model_user_group__create_start.php

    $r = \App\Repository\RepositoryFactory::group()->create($arr);
    // hook model_user_group__create_end.php
    return $r;
}
/**
 * @deprecated 兼容壳 — 按 gid 更新用户组行，转发 RepositoryFactory::group()->update()（不清 grouplist 缓存）。
 * @param mixed $gid 目标行主键
 * @param array $arr 列名=>值 更新集
 * @return int|FALSE 影响行数；入参为空时 FALSE
 */
function group__update($gid, $arr)
{
    // hook model_user_group__update_start.php

    $r = \App\Repository\RepositoryFactory::group()->update(array('gid' => $gid), $arr);
    // hook model_user_group__update_end.php
    return $r;
}
/**
 * @deprecated 兼容壳 — 按 gid 读单行用户组，转发 RepositoryFactory::group()->findOne()。
 * @param mixed $gid 目标行主键
 * @return array|null 行数组；未命中或查询失败为 NULL
 */
function group__read($gid)
{
    // hook model_user_group__read_start.php

    $group = \App\Repository\RepositoryFactory::group()->findOne(array('gid' => $gid));
    // hook model_user_group__read_end.php
    return $group;
}
/**
 * @deprecated 兼容壳 — 按 gid 删除用户组行，转发 RepositoryFactory::group()->delete()。
 * @param mixed $gid 目标行主键
 * @return int|FALSE 删除的影响行数
 */
function group__delete($gid)
{
    // hook model_user_group__delete_start.php

    $r = \App\Repository\RepositoryFactory::group()->delete(array('gid' => $gid));
    // hook model_user_group__delete_end.php
    return $r;
}
/**
 * @deprecated 兼容壳 — 分页查用户组列表，转发 RepositoryFactory::group()->find()（键列固定为 gid）。
 * @param array $cond     查询条件
 * @param array $orderby  排序
 * @param int   $page     页码
 * @param int   $pagesize 每页条数（默认 1000，近似全量）
 * @return array 以 gid 为键的行列表；无结果=空数组
 */
function group__find($cond = array(), $orderby = array(), $page = 1, $pagesize = 1000)
{
    // hook model_user_group__find_start.php

    $grouplist = \App\Repository\RepositoryFactory::group()->find($cond, $orderby, $page, $pagesize, 'gid');
    // hook model_user_group__find_end.php
    return $grouplist;
}
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\UserService::create()：插入用户行并 runtime_set 递增 users/todayusers 计数。新代码直接调 Service。
 * @param array $arr 整行用户数据
 * @return int|FALSE 自增 uid；$arr 为空时 FALSE
 */
function user_create($arr)
{
    // hook model_user_user_create_start.php

    // hook model_user_user_create_end.php
    return \App\Service\Account\UserService::create($arr);
}
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\UserService::update()：按 uid 更新，并精准失效 email/username/user- 各登录查询缓存（凭据变更必清）。
 * @param mixed $uid 目标用户 uid
 * @param array $arr 列名=>值 更新集
 * @return int|FALSE 影响行数；$uid 为空时 FALSE
 */
function user_update($uid, $arr)
{
    // hook model_user_user_update_start.php

    // hook model_user_user_update_end.php
    return \App\Service\Account\UserService::update($uid, $arr);
}
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\UserService::read()：user-{uid} 缓存(3600s)+请求级静态缓存，命中后 format() 装配展示字段。
 * @param mixed $uid 用户 uid（内部 intval）
 * @return array 用户行；uid 无效/未命中时为空数组（不返回 FALSE）
 */
function user_read($uid)
{
    // hook model_user_user_read_start.php

    // hook model_user_user_read_end.php
    return \App\Service\Account\UserService::read($uid);
}
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\UserService::readCache()：请求级静态缓存版读取，未命中回落 read()。
 * @param mixed $uid 用户 uid；0 视为游客
 * @return array 用户行；游客/未命中返回 guest() 游客结构（恒非空——与 user_read() 返回空数组的语义不同）
 */
function user_read_cache($uid)
{
    // hook model_user_user_read_cache_start.php

    // hook model_user_user_read_cache_end.php
    return \App\Service\Account\UserService::readCache($uid);
}
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\UserService::delete()：删行、失效 email/username/user- 缓存并 users 计数-1。
 * @param mixed $uid 用户 uid
 * @return int|FALSE 删除的影响行数；用户不存在时提前 FALSE
 */
function user_delete($uid)
{
    // hook model_user_user_delete_start.php

    // hook model_user_user_delete_end.php
    return \App\Service\Account\UserService::delete($uid);
}
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\UserService::find()：分页查并逐行 format()、回填请求级静态缓存。
 * @param array $cond     查询条件
 * @param array $orderby  排序
 * @param int   $page     页码
 * @param int   $pagesize 每页条数
 * @return array|null 以 uid 为键的用户列表；无结果返回 NULL（非空数组）
 */
function user_find($cond = array(), $orderby = array(), $page = 1, $pagesize = 20)
{
    // hook model_user_user_find_start.php

    // hook model_user_user_find_end.php
    return \App\Service\Account\UserService::find($cond, $orderby, $page, $pagesize);
}
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\UserService::readByEmail()：按邮箱读整行（含 password 散列）并缓存 3600s。
 * @param string $email 邮箱（转发目标签名 string）
 * @return array|null 用户行；未命中为 NULL。改密/删号时 Service 已同步失效该缓存
 */
function user_read_by_email($email)
{
    // hook model_user_user_read_by_email_start.php

    // hook model_user_user_read_by_email_end.php
    return \App\Service\Account\UserService::readByEmail($email);
}
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\UserService::readByUsername()：按用户名读整行并缓存 3600s。
 * @param string $username 用户名（转发目标签名 string）
 * @return array|null 用户行；未命中为 NULL
 */
function user_read_by_username($username)
{
    // hook model_user_user_read_by_username_start.php

    // hook model_user_user_read_by_username_end.php
    return \App\Service\Account\UserService::readByUsername($username);
}
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\UserService::maxId()：取 uid 列最大值。
 * @return int 最大 uid（无行时为 0，转发目标声明 int）
 */
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\UserService::format()：就地装配展示字段（create_ip_fmt/create_date_fmt/groupname/online_status/url 等）。
 * @param array $user 用户行，引用传递、就地修改
 * @return null 转发目标无返回值（空行直接 return），调用方不要依赖返回
 */
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\UserService::guest()：游客假行（uid=0/gid=0，请求内静态单例）。
 * @return array 游客结构（含 groupname/username 语言键文案）
 */
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\UserService::findByUids()：按 uid 串批量取用户（逐段 intval、0 滤除）。
 * @param string $uids 逗号分隔的 uid 串（转发目标签名 string）
 * @return array 以 uid 为键、format() 后的用户列表；入参为空/无有效 uid 时为空数组
 */
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\UserService::safeInfo()：对外输出前脱敏。
 * @param array $user 用户行（值传递，原数组不受影响）
 * @return array 剥除 password/salt/email/资金/实名/手机号/登录 IP 等敏感字段后的副本
 */
function user_safe_info($user)
{
    // hook model_user_user_safe_info_start.php

    // hook model_user_user_safe_info_end.php
    return \App\Service\Account\UserService::safeInfo($user);
}
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\UserAuthService::rest()：取当前会话用户，session 无 uid 时尝试免登录 token 续登。
 * @return array 当前用户行；未登录（uid 仍为空）时转发 user_read(0) 返回空数组
 */
function user_rest()
{
    // hook model_user_user_rest_start.php

    // hook model_user_user_rest_end.php
    return \App\Service\Account\UserAuthService::rest();
}
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\UserAuthService::tokenGet()：解析免登录 Cookie，无效时顺带 tokenClear()。
 * @return int|FALSE 有效 uid；FALSE=令牌缺失/校验不过（fail-closed，Cookie 已被清）
 */
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\UserAuthService::tokenGetDo()：解密令牌并校验 30 天硬时效、可选 IP/UA 指纹、密码散列（凭据比较均 hash_equals 恒时）。
 * @return int|FALSE 有效 uid；任一校验不过即 FALSE（不抛错、不留登录态）
 */
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\UserAuthService::tokenSet()：签发免登录令牌并写 30 天 Cookie（httponly+SameSite=Lax，HTTPS 下 secure）。
 * @param mixed $uid 用户 uid
 * @return string 令牌密文；$uid 为空时返回空字符串且不写 Cookie
 */
function user_token_set($uid)
{
    // hook model_user_user_token_set_start.php

    // hook model_user_user_token_set_end.php
    return \App\Service\Account\UserAuthService::tokenSet($uid);
}
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\UserAuthService::tokenClear()：以过去过期时间清除免登录令牌 Cookie。
 * @return void 转发目标无返回值
 */
function user_token_clear()
{
    // hook model_user_user_token_clear_start.php

    \App\Service\Account\UserAuthService::tokenClear();
    // hook model_user_user_token_clear_end.php
}
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\UserAuthService::tokenGen()：组装 ip/time/uid/密码散列 md5/UA md5 五元组，xn_encrypt（AES-256-GCM）加密，请求内静态缓存。
 * @param mixed $uid 用户 uid
 * @return string 令牌密文（加密失败按全局约定抛异常，fail-closed 不降级）
 */
/**
 * @deprecated 兼容壳：前台登录验证（原注释口径），转发 UserAuthService::loginCheck()。
 * 语义是**门禁而非查询**：只看 global $user 是否为空（$user 由引导层的会话/免登录 token 链装配），
 * 未登录即 `http_location(url('user-login'))` 302 跳登录页并 exit ⇒ **无返回值、通过时静默放行**，
 * 调用点后面不需要也不应该再写 return。
 * 挂接位置：route/member.php 的全局守卫（CLAUDE.md「开发约定·后台参数与门禁」口径：路由级守卫是主机制，
 * route_meta.php 只是可选项），route/user 系也复用同一实现。
 * ⚠ 它**不判权限组、不校验 CSRF/token**：gid 相关判定走 group_access()，写操作仍必须配
 *   user_bull_token_guard（§二.6 写操作三件套），别把本函数当安全边界。
 * ⚠ 只跳转不回跳原地址（referer 清洗是另一条链 httpReferer()），需要「登录后回跳」由调用方带参。
 * @return void 无返回值（未登录时经 http_location() 终止请求）
 */
function user_login_check()
{
    // hook model_user_user_login_check_start.php

    \App\Service\Account\UserAuthService::loginCheck();
    // hook model_user_user_login_check_end.php
}
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\UserAuthService::httpReferer()：回跳地址消毒（剥危险字符 + http(s) 协议白名单，排除指向登录/注册/改密等敏感页）。
 * @return string 安全的跳转目标 URL；来源非法时回退站点根 $conf['path']
 */
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\UserAuthService::authCheck($token)：免登录认证链接校验（1 小时时效 + 密码散列恒时比对），失败经 message() 输出错误并终止请求。
 * @param mixed $token 形式参——转发目标实际从 param(2) 取密文，未使用该形参
 * @return array 校验通过后的用户行
 */
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\GroupService::create()：新增用户组并清 grouplist 缓存。
 * @param array $arr 整行组数据（转发目标签名 array）
 * @return int|FALSE 自增 gid；$arr 为空时 FALSE
 */
function group_create($arr)
{
    // hook model_user_group_create_start.php

    // hook model_user_group_create_end.php
    return \App\Service\Account\GroupService::create($arr);
}
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\GroupService::update()：按 gid 更新用户组并清 grouplist 缓存。
 * @param mixed $gid 目标组 gid
 * @param array $arr 列名=>值 更新集
 * @return int|FALSE 影响行数
 */
function group_update($gid, $arr)
{
    // hook model_user_group_update_start.php

    // hook model_user_group_update_end.php
    return \App\Service\Account\GroupService::update($gid, $arr);
}
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\GroupService::read()（无缓存，底层 group__read 直查库）。
 * @param mixed $gid 目标组 gid
 * @return array|null 组行；未命中为 NULL
 */
function group_read($gid)
{
    // hook model_user_group_read_start.php

    // hook model_user_group_read_end.php
    return \App\Service\Account\GroupService::read($gid);
}
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\GroupService::delete()：删组并清 grouplist 缓存。
 * @param mixed $gid 目标组 gid
 * @return int|FALSE 影响行数；$gid 为空时 FALSE
 */
function group_delete($gid)
{
    // hook model_user_group_delete_start.php

    // hook model_user_group_delete_end.php
    return \App\Service\Account\GroupService::delete($gid);
}
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\GroupService::find()：分页查用户组（底层 group__find，键列固定 gid）。
 * @param array $cond     查询条件
 * @param array $orderby  排序，默认 gid 升序
 * @param int   $page     页码
 * @param int   $pagesize 每页条数（默认 1000，近似全量）
 * @return array 以 gid 为键的组列表；无结果=空数组
 */
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\GroupService::name()：从全局 $grouplist 取组名（不查库）。
 * @param mixed $gid 组 gid
 * @return string 组名；$grouplist 未装载或未命中时为空字符串
 */
function group_name($gid)
{
    // hook model_user_group_name_start.php

    // hook model_user_group_name_end.php
    return \App\Service\Account\GroupService::name($gid);
}
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\GroupService::count()（RepositoryFactory::group()->count()）。
 * @param array $cond 查询条件，空数组=全表
 * @return int 用户组数（转发目标声明 int）
 */
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\GroupService::maxId()：取 gid 列最大值。
 * @return int 最大 gid（无行时为 0，转发目标声明 int）
 */
function group_maxid()
{
    // hook model_user_group_maxid_start.php

    // hook model_user_group_maxid_end.php
    return \App\Service\Account\GroupService::maxId();
}
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\GroupService::access()：用户组权限位判定，fail-closed（后台路由门禁依赖，勿改成放行）。
 * @param mixed  $gid    组 gid；空值直接拒绝
 * @param string $access 权限位名（如 'intoadmin'；转发目标签名 string）
 * @return bool gid=1（管理组）恒 TRUE；组不存在=FALSE（脏数据不放行）；否则看组行 $access 字段是否非空。请求内 static 缓存。
 *              判定源为全局 $grouplist，调用前需保证已装载（group_get()/group_list_cache()）。
 */
function group_access($gid, $access)
{
    // hook model_user_group_access_start.php

    // hook model_user_group_access_end.php
    return \App\Service\Account\GroupService::access($gid, $access);
}
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\GroupService::listCache()：'grouplist' 分布式缓存版全量组表。
 * @return array 以 gid 为键的组列表；缓存未命中回落 group__find() 全量查询并回写缓存
 */
function group_list_cache()
{
    // hook model_user_group_list_cache_start.php

    // hook model_user_group_list_cache_end.php
    return \App\Service\Account\GroupService::listCache();
}
/**
 * @deprecated 兼容壳 — 转发 \App\Service\Account\GroupService::listCacheDelete()：清 'grouplist' 缓存键。
 * @return bool|null cache_delete() 结果；缓存句柄不可用时为 NULL
 */
function group_list_cache_delete()
{
    // hook model_user_group_list_cache_delete_start.php

    // hook model_user_group_list_cache_delete_end.php
    return \App\Service\Account\GroupService::listCacheDelete();
}
$g_grouplist = FALSE;
