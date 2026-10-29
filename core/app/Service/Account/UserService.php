<?php
namespace App\Service\Account;

class UserService
{
    /** 用户静态缓存（原全局 $g_static_users 收敛，跨方法共享、每请求重置） */
    private static $g_static_users = array();

    /**
     * 新增用户：转 user__create()（→ RepositoryFactory::user()->insert()）后把运营计数 users、todayusers 各 +1。
     * runtime_set() 只改请求内 global $runtime 数组，落盘由 register_shutdown_function 的 runtime_save() 完成
     * （写 cache 键 runtime）；user 表不在页缓存内容表白名单内 ⇒ 不推进整页缓存版本令牌。
     * ⚠ 本方法不做唯一性校验（user 表有 UNIQUE KEY(username)/UNIQUE KEY(email)），也不清任何读缓存。
     * ⚠ 失败归一（原缺陷：注册失败被当成功）：底层 QueryBuilder::insert() 的声明返回类型是 int，
     *   语句失败时 PDOException 被 prepare_execute 吞掉、只回 **0**（撞唯一键即此路径）。
     *   user 主键是 AUTO_INCREMENT，成功必然 ≥1 ⇒ 这里把 0/FALSE 一律归一成 FALSE，
     *   调用方的 `FALSE === $uid` 判定因此成立（前台注册链路 core/app/Service/Front/UserService.php）；
     *   同时**失败不再递增 users/todayusers**（原实现先加计数再返回，失败注册也会计数）。
     * @param array $data 列名=>值 整行数据（空数组由底层 insert() 直接回 FALSE）
     * @return int|FALSE 成功返回新自增 uid（≥1）；写失败（含撞唯一键）与空入参均返回 FALSE
     */
    public static function create(array $data)
    {
        $result = user__create($data);
        if (FALSE === $result || (int)$result < 1) {
            // 写失败：不动运营计数、不消登记，直接把 FALSE 交给调用方判处
            return FALSE;
        }
        runtime_set('users+', 1);
        runtime_set('todayusers+', 1);
        return $result;
    }

    /**
     * 更新成功后清理用户缓存及旧版登录索引缓存；凭据变化时吊销其它会话。
     * 失败返回 FALSE，成功返回影响行数（包含合法的 0），失败不修改缓存。
     */
    public static function update($uid, array $data)
    {
        if (empty($uid)) return FALSE;
        $cur = self::read($uid);
        $result = user__update($uid, $data);
        // 写失败时保留真实缓存和会话，不能把未持久化的密码合并到内存。
        if (FALSE === $result) return FALSE;
        foreach (array('email' => 'user_email_', 'username' => 'user_name_') as $field => $prefix) {
            if (!empty($cur[$field])) cache_delete($prefix . md5($cur[$field]));
            if (!empty($data[$field])) cache_delete($prefix . md5($data[$field]));
        }
        cache_delete('user-' . $uid);
        unset(self::$g_static_users[$uid]);
        if (isset($data['password']) || isset($data['salt'])) {
            $curSid = (PHP_SESSION_ACTIVE === session_status()) ? (string)session_id() : '';
            \App\Service\Account\SessionService::destroyByUid((int)$uid, $curSid);
        }
        return $result;
    }

    /**
     * 按 uid 读用户（两级缓存）：请求级静态表 self::$g_static_users ⇒ 分布式缓存 user-{uid}（TTL 3600s）⇒ 回表。
     * uid 先 intval 归一（'12abc' 与 12 同键）；缓存的是**经 format() 装配后**的行（含 groupname/url/online_status 等展示键）。
     * 负结果只进请求级静态表、不写分布式缓存 ⇒ 不存在的 uid 每次回表。写侧必须 cache_delete('user-'.$uid) 才失效。
     * @param mixed $uid 用户主键（内部 intval；0/空/非数字 ⇒ 空数组）
     * @return array 用户行数组；不存在返回**空数组**（非 NULL、非 FALSE），调用方用 empty() 判断即可
     */
    public static function read($uid)
    {
        $uid = intval($uid);
        if (empty($uid)) return array();
        if (isset(self::$g_static_users[$uid])) return self::$g_static_users[$uid];
        $key = 'user-' . $uid;
        $user = cache_get($key);
        if (NULL === $user) {
            $user = user__read(array('uid' => $uid));
            if ($user) {
                self::format($user);
                cache_set($key, $user, 3600);
            }
        }
        self::$g_static_users[$uid] = $user ? $user : array();
        return self::$g_static_users[$uid];
    }

    /**
     * 面向前台/权限链的用户读取：$uid 松散等于 0（0 / '0' / 0.0，PHP 8 下非数字串不等）直接返回 guest() 访客占位行，
     * 否则走 read() 两级缓存。与 read() 的关键差别是**永不返回空数组**：查无此人时回落 guest()，
     * 故调用方拿到的 gid 恒有值（fail-to-guest，不是 fail-closed——权限判定要自己再校验 gid）。
     * ⚠ 副作用：走 read() 分支时，未命中的访客形态也会写进请求级静态表 self::$g_static_users[$uid]
     *   ⇒ 同请求内该 uid 后续直接命中访客行；uid=0 短路分支不写该表。
     * @param mixed $uid 用户主键（本方法不做 intval，键按原值语义使用：0 与 '0' 因 PHP 数组键归一为同键）
     * @return array 用户行或访客行（含 uid/gid/groupname/username/email 等固定键）
     */
    public static function readCache($uid)
    {
        if (isset(self::$g_static_users[$uid])) return self::$g_static_users[$uid];
        if (0 == $uid) return self::guest();
        $row = self::read($uid);
        self::$g_static_users[$uid] = $row ? $row : self::guest();
        return self::$g_static_users[$uid];
    }

    /**
     * 删除用户并清全部相关读缓存：先 read() 取行（不存在直接 FALSE，不发删除），
     * 再清 user_email_{md5} / user_name_{md5} / user-{uid} 三个分布式键与请求级静态表，最后 users 计数 -1。
     * ⚠ 只删 user 表一行：不动 session/session_data、favorite、like 等关联数据（会话残留由会话层清理）。
     * runtime_set('users-',1) 只改请求内数组，由 shutdown 的 runtime_save() 落缓存。
     * @param mixed $uid 用户主键（read() 侧按 intval 归一；user__delete 按原值绑定 uid 条件）
     * @return int|FALSE 删除的影响行数；用户不存在或底层删除失败为 FALSE
     */
    public static function delete($uid)
    {
        $user = self::read($uid);
        if (empty($user)) return FALSE;
        // 审计修复：删除账号须同时失效 email/username 查询缓存，否则
        // readByEmail 命中已删行、旧密码在缓存过期前仍可登录。
        if (!empty($user['email'])) cache_delete('user_email_' . md5($user['email']));
        if (!empty($user['username'])) cache_delete('user_name_' . md5($user['username']));
        $result = user__delete($uid);
        cache_delete('user-' . $uid);
        if (isset(self::$g_static_users[$uid])) unset(self::$g_static_users[$uid]);
        runtime_set('users-', 1);
        return $result;
    }

    /**
     * 用户分页列表（后台管理口径）：转 user__find() 按 uid 倒/正序分页，逐行 format() 并回填请求级静态表。
     * ⚠ $col 未透传 ⇒ 底层走 SELECT *（user 表含 password/salt 等敏感列），返回行仍是**全字段**：
     *   对外展示前必须先过 safeInfo() 剥敏感键。
     * @param array $cond     查询条件（列=>值；值传数组走 IN）
     * @param array $orderby  排序（列=>1 升序 / -1 降序）
     * @param int   $page     页码（签名已声明，从 1 起）
     * @param int   $pagesize 每页条数（签名已声明）
     * @return array|null 以 uid 为键的行列表；**无结果返回 NULL**（与 user__find 的空数组不同）
     */
    public static function find(array $cond = array(), array $orderby = array(), int $page = 1, int $pagesize = 20)
    {
        $userlist = user__find($cond, $orderby, $page, $pagesize);
        if (!$userlist) return NULL;
        foreach ($userlist as &$user) {
            self::format($user);
            self::$g_static_users[$user['uid']] = $user;
        }
        unset($user);
        return $userlist;
    }

    /**
     * 登录和找回密码始终从主库读取凭据。数据库排序规则可能将不同大小写、重音
     * 视为同一账户，不能按输入字符串缓存整行密码；旧版别名缓存也不再读取。
     */
    public static function readByEmail(string $email)
    {
        return self::readForLogin('email', $email);
    }

    public static function readByUsername(string $username)
    {
        return self::readForLogin('username', $username);
    }

    private static function readForLogin(string $field, string $value)
    {
        $db = _SERVER('db');
        try {
            $pdo = $db->connect();
            if (!$pdo) return NULL;
            $stmt = $pdo->prepare('SELECT * FROM ' . $db->qi($db->tablepre . 'user')
                . ' WHERE ' . $db->qi($field) . ' = ? LIMIT 1');
            $stmt->execute(array($value));
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$user) return NULL;
            self::format($user);
            self::$g_static_users[$user['uid']] = $user;
            return $user;
        } catch (\PDOException $e) {
            xn_log('credential read failed: ' . $e->getCode(), 'db_error');
            return NULL;
        }
    }
    /**
     * 取 user 表当前最大 uid：直连 RepositoryFactory::user()->maxId('uid')（不经本服务的任何缓存层）。
     * 用途是「下一个可用 id」一类容量探测，不保证无并发空隙。
     * @return int 最大 uid；表空为 0（DbTableRepository::maxId 声明 int）
     */
    public static function maxId()
    {
        return \App\Repository\RepositoryFactory::user()->maxId('uid');
    }

    /**
     * 访客占位行（uid=0/gid=0）：请求内 static 只造一次，供 readCache() 与未登录链路当「有结构的空用户」用，
     * 免得调用方到处判 NULL。文案取 lang('guest_group') / lang('guest') ⇒ 同一请求内语言切换不会刷新该值。
     * @return array 固定骨架：uid/gid/groupname/username/create_ip_fmt/create_date_fmt/login_date_fmt/email
     */
    public static function guest()
    {
        static $guest = NULL;
        if ($guest) return $guest;
        $guest = array(
            'uid' => 0,
            'gid' => 0,
            'groupname' => lang('guest_group'),
            'username' => lang('guest'),
            'create_ip_fmt' => '',
            'create_date_fmt' => '',
            'login_date_fmt' => '',
            'email' => '',
        );
        return $guest;
    }

    /**
     * 按逗号分隔的 uid 串批量取用户：trim → explode → intval → array_filter 去 0 ⇒ 空集直接返回空数组，
     * 否则一次 user__find(IN) 取回（pagesize=count(去重前的 id 数)）并逐行 format()，结果以 uid 为键。
     * ⚠ 未走缓存层（每次都查库），也无 500 条批量上限—— uid 串来源若可被外部控制，调用方需自行限长。
     * @param string $uids 逗号分隔的 uid 串（签名已声明；'1,2,3' 形态，非法/0 项被丢弃）
     * @return array uid 为键的用户行列表（含敏感列，输出前需 safeInfo()）；无有效 id 或无命中为空数组
     */
    /**
     * 对外展示前的敏感字段剥离（白名单外的「减列」口径）：unset 掉 password/salt/credits/golds/money/email/
     * mobile/idnumber/realname/qq、各 ip 与日期原值及 fmt 列、logins、avatar_path 共 21 个键后返回。
     * 值拷贝语义：$user 是值参（非引用），unset 只影响返回的副本，调用方原数组仍完整。
     * @param array $user 用户行（签名已声明；通常来自 read()/find()/findByUids()，缺键不报错）
     * @return array 剥敏后的行；⚠ create_ip_fmt/create_date_fmt/login_date_fmt 也在剔除之列，
     *         若模板需要展示「注册时间/最后登录」，得由调用方另行取值
     */
    public static function safeInfo(array $user)
    {
        unset($user['password'], $user['credits'], $user['golds'], $user['money'], $user['email'], $user['salt'], $user['password_sms'], $user['idnumber'], $user['realname'], $user['qq'], $user['mobile'], $user['create_ip'], $user['create_ip_fmt'], $user['create_date'], $user['create_date_fmt'], $user['login_ip'], $user['login_date'], $user['login_ip_fmt'], $user['login_date_fmt'], $user['logins'], $user['avatar_path']);
        return $user;
    }

    // 用户展示格式化（原 model/user.php user_format 搬入）
    public static function format(&$user)
    {
        $conf = _SERVER('conf');
        if (empty($user)) return;
        $user['create_ip_fmt'] = safe_long2ip($user['create_ip']);
        $user['create_date_fmt'] = empty($user['create_date']) ? '0000-00-00' : date('Y-m-d', $user['create_date']);
        $user['login_ip_fmt'] = safe_long2ip($user['login_ip']);
        $user['login_date_fmt'] = empty($user['login_date']) ? '0000-00-00' : date('Y-m-d', $user['login_date']);
        $user['groupname'] = group_name($user['gid']);
        $onlinelist = online_user_list_cache();
        $user['online_status'] = isset($onlinelist[$user['uid']]) ? 1 : 0;
        $user['url'] = url('user-' . $user['uid']);
    }
}

