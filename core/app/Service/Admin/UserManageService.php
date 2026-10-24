<?php
namespace App\Service\Admin;

class UserManageService
{
    // 组后台权限列全集（与 install.sql nncms_group 表列逐字一致；补 managead；
    // 补 manageplugin——列已在 install.sql 与现库，键表补齐后组编辑器可授权，gid=1 恒过不变）
    const GROUP_PERM_KEYS = array('manageuser', 'managecreateuser', 'manageupdateuser', 'managedeleteuser', 'manageupdategroup', 'managegroup', 'managevod', 'manageart', 'managecate', 'managecontent', 'managelink', 'managehot', 'manageapi', 'manageset', 'managetmpl', 'managead', 'manageplugin');

    /**
     * 用户管理列表页视图数据：按 keyword/srchtype 组 cond 后走 user_count + user_find
     * （uid 降序、每页 20），产出后台模板变量数组。
     * ⚠ searchCond 产出的 'srchtype' 键只用于回显，查库前已 unset，不会流入 cond。
     * @param array $filters 路由侧组装：page / keyword / srchtype（均已 param 转义）
     * @param array $grouplist 组列表（本页仅透传意图由 createPage/updatePage 消费）
     * @param int $uid 当前管理员 uid（token 场景绑定用）
     * @return array 含 header/keyword/userlist（恒为数组，无结果=空数组）/pagination/safe_token
     */
    public static function listPage(array $filters, array $grouplist, int $uid): array
    {
        $page = max(1, intval($filters['page'] ?? 1));
        $pagesize = 20;
        $keyword = trim((string)($filters['keyword'] ?? ''));
        $srchtype = trim((string)($filters['srchtype'] ?? ''));
        $extra = array('page' => '{page}');
        $cond = self::searchCond($keyword, $srchtype);
        $srchtype = array_key_exists('srchtype', $cond) ? $cond['srchtype'] : $srchtype;
        unset($cond['srchtype']);

        $keyword !== '' and $extra['keyword'] = urlencode($keyword);
        $srchtype !== '' and $extra['srchtype'] = $srchtype;

        $n = user_count($cond);
        $userlist = user_find($cond, array('uid' => -1), $page, $pagesize);
        if (empty($userlist)) $userlist = array();

        return array(
            'header' => array(
                'title' => lang('user_admin'),
                'mobile_title' => lang('user_admin'),
            ),
            'keyword' => $keyword,
            'userlist' => $userlist,
            'pagination' => pagination(url('user-list', $extra, TRUE), $n, $page, $pagesize),
            // 场景绑定：本 token 仅供删除表单使用，其他页面签发的 token 不可复用
            'safe_token' => bull_token_set($uid, 'admin_user_delete'),
        );
    }

    /**
     * 创建用户表单页视图数据：表单控件 HTML 由 userFormInputs 产出，
     * token 场景 'admin_create_user'（与 create() 校验同场景）。
     * @param array $grouplist 组下拉数据源（gid=>name 由 userFormInputs 内部转换）
     * @param int $uid 当前管理员 uid
     * @return array header + input（email/username/password/_gid/safe_token/show_password）
     */
    public static function createPage(array $grouplist, int $uid): array
    {
        return array(
            'header' => array(
                'title' => lang('admin_user_create'),
                'mobile_title' => lang('admin_user_create'),
            ),
            // 审查修复轮 C11：新建账号组预选从「游客组(0)」改为「普通会员(2)」——旧默认会让
            // 不碰组下拉的管理员建出无前台权限的游客账号（前台注册链路的默认组也是 2）。
            'input' => self::userFormInputs($grouplist, 2, '', '', $uid, 'admin_create_user'),
        );
    }

    /**
     * 后台创建用户（POST 写路径）：admin_create_user token + managecreateuser 权限位双闸
     * （fail-closed：任一不过即 message 中断请求，后续语句不再执行）→
     * userPayload(TRUE) 采集校验 → user_create（附带 create_ip/create_date）→ 审计。
     * @param int $uid 操作者 uid（token 绑定）
     * @param int $gid 操作者组（权限位判定用；目标组在 payload._gid）
     * @param int $longip 请求长整型 IP（create_ip）
     * @param int $time 请求时间戳（create_date）
     * @return int 新用户 uid；所有失败路径经 message() 终止请求（不返回）
     */
    public static function create(int $uid, int $gid, int $longip, int $time): int
    {
        self::verifyToken($uid, 'admin_create_user');
        FALSE === group_access($gid, 'managecreateuser') and message(1, lang('user_group_insufficient_privilege'));

        $payload = self::userPayload(TRUE, $gid);
        $payload['create_ip'] = $longip;
        $payload['create_date'] = $time;
        $userId = user_create($payload);
        FALSE === $userId and message(-1, lang('create_failed'));

        admin_audit_log('user_create', array('username' => $payload['username'], 'email' => $payload['email'], 'gid' => $payload['gid']));
        return intval($userId);
    }

    /**
     * 编辑用户表单页视图数据：先 user_read 目标行（不存在 ⇒ message 中断），
     * 表单预填目标当前 gid/email/username，$includePassword=TRUE 让模板展示密码区（编辑可改密）。
     * @param int $targetUid 被编辑用户 uid
     * @param array $grouplist 组下拉数据源
     * @param int $uid 当前管理员 uid（token 绑定）
     * @return array header/extra/input；目标不存在经 message() 终止请求
     */
    public static function updatePage(int $targetUid, array $grouplist, int $uid): array
    {
        $user = user_read($targetUid);
        empty($user) and message('username', lang('uid_not_exists'));

        return array(
            'header' => array(
                'title' => lang('user_edit'),
                'mobile_title' => lang('user_edit'),
            ),
            'extra' => array('uid' => $targetUid),
            'input' => self::userFormInputs($grouplist, intval($user['gid']), $user['email'], $user['username'], $uid, 'admin_update_user', TRUE),
        );
    }

    /**
     * 后台编辑用户（POST 写路径）：admin_update_user token + manageupdateuser 权限位 +
     * 越权闸（目标 gid 高于操作者组即拒，见体内注释，fail-closed 经 message 中断）→
     * userPayload(FALSE,$gid,$targetUid) 采集校验 → 与库内旧行逐值 diff 后仅写变更字段。
     * 改密走 userPayload 的 password_hash_custom 分支；user_update 内部负责会话/查询缓存失效。
     * @param int $targetUid 被编辑用户 uid
     * @param int $uid 操作者 uid
     * @param int $gid 操作者组（权限位与越权判定）
     * @return array 实际变更的字段集（array_diff_value 结果，空数组=无变更，此时不发 UPDATE）
     */
    public static function update(int $targetUid, int $uid, int $gid): array
    {
        self::verifyToken($uid, 'admin_update_user');
        FALSE === group_access($gid, 'manageupdateuser') and message(1, lang('user_group_insufficient_privilege'));

        $old = user_read($targetUid);
        empty($old) and message('username', lang('uid_not_exists'));
        // 越权修复：目标当前 gid 高于（数值小于）操作者所属组时禁止编辑——
        // 此前 userPayload 只比对提交值 _gid、从不读目标现状，gid=2 账号可重置/降级超管密码实现接管
        if ($gid > 1 && intval($old['gid']) < $gid) {
            message(1, lang('user_group_insufficient_privilege'));
        }

        $payload = self::userPayload(FALSE, $gid, $targetUid);
        $update = array_diff_value($payload, $old);
        !empty($update) && FALSE === user_update($targetUid, $update) and message(-1, lang('update_failed'));

        admin_audit_log('user_update', array('uid' => $targetUid, 'fields' => array_keys($update)));
        return $update;
    }

    /**
     * 后台删除用户（POST 写路径）：managedeleteuser 权限位 + admin_user_delete 场景 token
     * 双闸（fail-closed）；gid=1 超管恒不可删；非超管不得删权限高于本组的账号；
     * user_delete 内部清用户缓存并回退 users 计数。
     * @param int $targetUid 被删用户 uid
     * @param int $uid 操作者 uid
     * @param int $gid 操作者组
     * @return int 已删的 targetUid（仅成功路径可达；各失败分支经 message() 终止请求）
     */
    public static function delete(int $targetUid, int $uid, int $gid): int
    {
        FALSE === group_access($gid, 'managedeleteuser') and message(1, lang('user_group_insufficient_privilege'));
        self::verifyToken($uid, 'admin_user_delete');

        $user = user_read($targetUid);
        empty($user) and message(-1, lang('user_not_exists'));
        (1 == intval($user['gid'])) and message(-1, lang('user_admin_cant_be_deleted'));
        // 越权修复：同 update 口径，非超管不得删除权限高于本组的账号
        if ($gid > 1 && intval($user['gid']) < $gid) {
            message(1, lang('user_group_insufficient_privilege'));
        }
        FALSE === user_delete($targetUid) and message(-1, lang('delete_failed'));

        admin_audit_log('user_delete', array('uid' => $targetUid));
        return $targetUid;
    }

    /**
     * 分组管理列表页视图数据：渲染入参组表 + 保护组名单 + 新组起始 gid（maxgid+1 由模板用）。
     * @param array $grouplist gid 为键的组行集（group_list_cache 装载）
     * @param array $systemGroup 系统保护组 gid 列表（路由侧 array(0,1,2)，删组时跳过）
     * @param int $uid 当前管理员 uid
     * @return array header/grouplist/system_group/maxgid/safe_token('admin_save_groups' 场景)
     */
    public static function groupListPage(array $grouplist, array $systemGroup, int $uid): array
    {
        return array(
            'header' => array(
                'title' => lang('group_admin'),
                'mobile_title' => lang('group_admin'),
            ),
            'grouplist' => $grouplist,
            'system_group' => $systemGroup,
            'maxgid' => group_maxid(),
            // 场景绑定：仅供分组批量保存表单使用
            'safe_token' => bull_token_set($uid, 'admin_save_groups'),
        );
    }

    /**
     * 批量保存分组表（POST 写路径）：admin_save_groups token + managegroup 权限位双闸
     * （fail-closed）后逐行处理 groupBatchPayload 的行集：
     *  - 越权守卫：非超管只能编辑自己组、禁止新建组（防凭空建组授满权限提权）；
     *  - 权限列逐位读 param($permKey) 复选框数组，再经 clampPermsToOperator 与操作者自身
     *    权限取交集（防自授 manageplugin → RCE）；组名 filter_all_html 入库净化（防存储型 XSS）；
     *  - exists=TRUE 走 group_update、FALSE 走 group_create；
     *  - 未出现在提交行集中的原组=删除意图：非超管一律拒绝，超管删时跳过 systemGroup 保护组。
     * 收尾清组列表缓存并审计（saved/deleted 计数）。
     * @param array $grouplist 保存前的组行集（gid 为键，用于判定 exists 与删除差集）
     * @param array $systemGroup 保护组 gid 列表
     * @param int $uid 操作者 uid
     * @param int $gid 操作者组（越权判定）
     * @return void 失败路径经 message() 终止请求；返回即表示已落库
     */
    public static function saveGroups(array $grouplist, array $systemGroup, int $uid, int $gid): void
    {
        self::verifyToken($uid, 'admin_save_groups');
        FALSE === group_access($gid, 'managegroup') and message(1, lang('user_group_insufficient_privilege'));

        $rows = self::groupBatchPayload($grouplist);
        foreach ($rows as $row) {
            // 越权守卫（与 updateGroup 同口径，上线前加固）：非超管仅可编辑本组，
            // 不得创建新组（防凭空建组授满权限提权）
            if (1 != $gid && (intval($row['gid']) != intval($gid) || empty($row['exists']))) {
                message(1, lang('user_group_insufficient_privilege'));
            }
            $perm = array();
            foreach (self::GROUP_PERM_KEYS as $p) {
                $vals = param($p, array());
                $perm[$p] = (is_array($vals) && !empty($vals[$row['gid']])) ? 1 : 0; // 组权限复选框读写
            }
            // 审计修复：非超管只能保留自己组已持有的权限位（防自授 manageplugin 等 → RCE）
            $perm = self::clampPermsToOperator($perm, $gid);
            $row['name'] = filter_all_html($row['name']); // 组名入库净化（防后台存储型 XSS）
            if (!empty($row['exists'])) {
                group_update($row['gid'], array('gid' => $row['gid'], 'name' => $row['name']) + $perm);
            } else {
                group_create(array('gid' => $row['gid'], 'name' => $row['name']) + $perm);
            }
        }

        $deletearr = array_diff_key($grouplist, $rows);
        if (1 != $gid && !empty($deletearr)) {
            // 非超管禁止删除任何组（防删他组破坏权限体系）
            message(1, lang('user_group_insufficient_privilege'));
        }
        foreach ($deletearr as $groupId => $group) {
            if (in_array($groupId, $systemGroup)) continue;
            group_delete($groupId);
        }
        group_list_cache_delete();

        admin_audit_log('group_save_batch', array('saved' => count($rows), 'deleted' => count($deletearr)));
    }

    /**
     * 审计修复：非超级管理员（gid!=1）的组权限写入掩码。
     * 只允许保留操作者所在组已持有的权限位，禁止自行提权（缺失位一律归 0）。
     * 仅处理权限列与 allowread/intoadmin，不影响 name 等其它字段。
     */
    private static function clampPermsToOperator(array $want, int $gid): array
    {
        if (1 == $gid) return $want;
        $op = group_read($gid);
        foreach (self::GROUP_PERM_KEYS as $p) {
            $want[$p] = (!empty($want[$p]) && !empty($op[$p])) ? 1 : 0;
        }
        foreach (array('allowread', 'intoadmin') as $p) {
            if (array_key_exists($p, $want)) {
                $want[$p] = (!empty($want[$p]) && !empty($op[$p])) ? 1 : 0;
            }
        }
        return $want;
    }

    /**
     * 单组编辑页视图数据：group_read 目标组（不存在 ⇒ message 中断）+
     * manageupdategroup 权限位闸；input 含组名文本、allowread/intoadmin 复选框、
     * 全权限位复选框（groupPermCheckboxes）与 'admin_update_group' 场景 token 隐藏域。
     * @param int $targetGid 被编辑组 gid
     * @param int $uid 当前管理员 uid（token 签发）
     * @param int $gid 当前管理员组（权限位判定）
     * @return array header/_group/extra/input
     */
    public static function groupUpdatePage(int $targetGid, int $uid, int $gid): array
    {
        $group = group_read($targetGid);
        empty($group) and message(-1, lang('group_not_exists'));
        FALSE === group_access($gid, 'manageupdategroup') and message(1, lang('user_group_insufficient_privilege'));

        return array(
            'header' => array(
                'title' => lang('group_admin'),
                'mobile_title' => lang('group_admin'),
            ),
            '_group' => $group,
            'extra' => array('gid' => $targetGid),
            'input' => array(
                'name' => form_text('name', $group['name']),
                'allowread' => form_checkbox('allowread', $group['allowread'], lang('allow_view')),
                'intoadmin' => form_checkbox('intoadmin', $group['intoadmin'], lang('in_to_admin')),
                'perms' => self::groupPermCheckboxes($group),
                'safe_token' => form_hidden('safe_token', bull_token_set($uid, 'admin_update_group')),
            ),
        );
    }

    /**
     * 按 GROUP_PERM_KEYS 全集生成后台权限复选框 HTML 列表。
     * $labels 未覆盖的键（如 manageplugin）以原始键名充当显示文案兜底。
     * @param array $group 目标组行（读各权限位现值决定勾选态）
     * @return array form_checkbox 产出的 HTML 字符串数组（顺序与 GROUP_PERM_KEYS 一致）
     */
    private static function groupPermCheckboxes(array $group): array
    {
        $labels = array(
            'manageuser' => '用户管理', 'managecreateuser' => '创建用户', 'manageupdateuser' => '编辑用户',
            'managedeleteuser' => '删除用户', 'manageupdategroup' => '编辑组', 'managegroup' => '组管理',
            'managevod' => '视频管理', 'manageart' => '文章管理', 'managecate' => '分类管理',
            'managecontent' => '内容管理', 'managelink' => '链接管理', 'managehot' => '热词管理',
            'manageapi' => '采集管理', 'manageset' => '系统设置', 'managetmpl' => '模板管理',
            'managead' => '广告管理',
        );
        $out = array();
        foreach (self::GROUP_PERM_KEYS as $p) {
            $out[] = form_checkbox($p, empty($group[$p]) ? 0 : 1, isset($labels[$p]) ? $labels[$p] : $p);
        }
        return $out;
    }

    /**
     * 单组权限保存（POST 写路径）：admin_update_group token + manageupdategroup 权限位 +
     * 「非超管只能编辑本组」三重 fail-closed 闸 → 组名 admin_param_text(50)+filter_all_html
     * 净化入库（防存储型 XSS）→ 全权限位读 param 并经 clampPermsToOperator 掩码
     * （非超管只能保留自身已有位，堵 F-03 自提权→manageplugin RCE 链）→ group_update + 审计。
     * @param int $targetGid 目标组 gid
     * @param int $uid 操作者 uid
     * @param int $gid 操作者组
     * @return void 失败路径经 message() 终止请求
     */
    public static function updateGroup(int $targetGid, int $uid, int $gid): void
    {
        self::verifyToken($uid, 'admin_update_group');
        FALSE === group_access($gid, 'manageupdategroup') and message(1, lang('user_group_insufficient_privilege'));

        // 非超级管理员只能编辑自己所在组，防提权式越组配置（审计修复）
        (1 != $gid && $targetGid != $gid) and message(1, lang('user_group_insufficient_privilege'));
        $name = filter_all_html(admin_param_text('name', '', 50)); // 组名入库净化（防后台存储型 XSS）
        !$name and message(1, lang('group_name_empty'));
        $arr = array(
            'name' => $name,
            'allowread' => param('allowread', 0),
            'intoadmin' => param('intoadmin', 0),
        );
        // 组权限列读写（此前 group 表缺列致非 gid=1 组后台全被拒；审计修复）
        foreach (self::GROUP_PERM_KEYS as $p) {
            $arr[$p] = param($p, 0) ? 1 : 0;
        }
        // 审计修复（F-03 自提权）：非超管（gid!=1）只能编辑本组，但此前可勾选任意
        // 权限位自授 manageplugin（插件上传=RCE）/manageset/manageuser 等，等效自升全权管理员。
        // 现与操作者自身权限位取交集：只能保留自己已持有的位，不能新增。
        $arr = self::clampPermsToOperator($arr, $gid);
        group_update($targetGid, $arr);
        admin_audit_log('group_update', array('gid' => $targetGid, 'name' => $name));
    }

    /**
     * 产出用户表单控件 HTML 集（供 createPage/updatePage 视图 extract 后直接 echo）。
     * 组下拉数据源由 arrlist_key_values 从 $grouplist 提取 gid=>name。
     * @param array $grouplist gid 为键的组行集
     * @param int $groupId 表单预选组 gid（新建=0）
     * @param string $email 预填邮箱
     * @param string $username 预填用户名
     * @param int $uid token 绑定的操作者 uid
     * @param string $scene safe_token 场景名（须与写接口校验场景一致）
     * @param bool $includePassword TRUE=模板展示密码区（编辑态可改密）
     * @return array 控件 HTML 数组（email/username/password/_gid/safe_token/show_password）
     */
    private static function userFormInputs(array $grouplist, int $groupId, string $email, string $username, int $uid, string $scene, bool $includePassword = FALSE): array
    {
        $grouparr = arrlist_key_values($grouplist, 'gid', 'name');
        return array(
            'email' => form_text('email', $email),
            'username' => form_text('username', $username),
            'password' => form_password('password', ''),
            '_gid' => form_select('_gid', $grouparr, $groupId),
            'safe_token' => form_hidden('safe_token', bull_token_set($uid, $scene)),
            'show_password' => $includePassword ? 1 : 0,
        );
    }

    /**
     * 从 POST 采集并校验用户表单载荷（create/update 共用），任一校验不过即 message 终止：
     *  - email 必填 + is_email、username 选填 + is_username（param 默认已 htmlspecialchars）；
     *  - 提权防护：提交的目标组 $_gid>0 且数值小于操作者组（=权限更高）即拒绝；
     *  - email/username 唯一性查重（编辑态排除 $excludeUid 自身）；
     *  - 仅当 password 非空才装配散列：password_hash_custom(bcrypt) + salt 置空，
     *    编辑态留空=不改密；创建态空密码即拒。
     * 返回的载荷即为可直接写库的行数组（不含 create_ip/create_date，由 create() 补充）。
     * @param bool $isCreate TRUE=创建态校验（密码必填）
     * @param int $operatorGid 操作者组 gid（提权比对基准）
     * @param int $excludeUid 编辑态目标 uid（查重排除自身），0=创建态
     * @return array 键：email/username/gid，可选 password/salt
     */
    private static function userPayload(bool $isCreate, int $operatorGid, int $excludeUid = 0): array
    {
        $email = admin_param_text('email', '', 100);
        $username = admin_param_text('username', '', 32);
        $password = param('password');
        $_gid = admin_param_int('_gid', 2, 0);
        // 组存在性校验（复核修正）：目标组在 grouplist 里不存在时（外部删组/老库只有 0/1），
        // 旧实现照样写入 gid=2 ⇒ 账号能登录但 GroupService::access 与 CateService::accessUser 恒拒
        // （前台什么都读不到），而界面会按游客组兜底显示，极难诊断。现 fail-closed 拒绝保存。
        if (!isset($GLOBALS['grouplist'][$_gid])) {
            message('_gid', lang('user_group_not_exists'));
        }
        // 审查修复轮 C11：缺省组从 0（游客组）改 2（普通会员），与表单预选一致；显式提交 0 仍
        // 允许（管理员明确选择游客组时），不做隐性兜底。
        // 提权防护：目标组 gid 不得高于操作者自身组（gid 越小权限越高；审计修复）
        ($_gid > 0 && $_gid < $operatorGid) and message('_gid', lang('user_group_insufficient_privilege'));

        empty($email) and message('email', lang('please_input_email'));
        $email && !is_email($email, $err) and message('email', $err);
        $username && !is_username($username, $err) and message('username', $err);
        $password === '' && $isCreate and message('password', lang('please_input_password'));

        $_user = user_read_by_email($email);
        $_user && intval($_user['uid']) !== $excludeUid and message('email', $isCreate ? lang('email_is_in_use') : lang('email_already_exists'));
        $_user = user_read_by_username($username);
        $_user && intval($_user['uid']) !== $excludeUid and message('username', lang('user_already_exists'));

        $arr = array(
            'email' => $email,
            'username' => $username,
            'gid' => $_gid,
        );
        if ($password !== '') {
            // 安全审查 L-5：管理员组（gid=1）账号密码至少 12 位（与安装向导一致；非管理员沿用 is_password 口径）
            1 === $_gid && strlen((string)$password) < 12 and message('password', '管理员密码至少 12 位');
            $arr['password'] = password_hash_custom($password);
            $arr['salt'] = '';
        }

        return $arr;
    }

    /**
     * 列表页搜索词 → Repository cond 映射（白名单列，防任意列名注入条件）：
     *  - $srchtype 空时按关键词形态猜列：含 @ → email，否则 username；
     *  - 非法 $srchtype（不在 uid/username/email/gid/create_ip 白名单）兜底为 uid；
     *  - create_ip 特殊：keyword 经 ip2long 后 sprintf('%u') 转长整型 IP（v4 恒正）；
     *    非法 IP 时 ip2long 返回 FALSE ⇒ %u 格式化为 0（查不到而非报错）。
     * ⚠ 返回数组额外带 'srchtype' 键仅作回显，查库前调用方必须 unset（listPage 已处理）。
     * @param string $keyword 已 trim 的搜索词
     * @param string $srchtype 搜索列名（可空）
     * @return array 空 keyword ⇒ 空数组（无条件）
     */
    private static function searchCond(string $keyword, string $srchtype): array
    {
        $cond = array();
        if ($keyword === '') return $cond;

        $allowtype = array('uid', 'username', 'email', 'gid', 'create_ip');
        if ($srchtype === '') {
            $srchtype = str_contains($keyword, '@') ? 'email' : 'username';
        }
        !in_array($srchtype, $allowtype, TRUE) and $srchtype = 'uid';
        $cond[$srchtype] = 'create_ip' == $srchtype ? sprintf('%u', ip2long($keyword)) : $keyword;
        $cond['srchtype'] = $srchtype;
        return $cond;
    }

    /**
     * 解析批量组编辑的 POST 行集：读 _gid[]/name[] 两个平行数组（param 数组口径：
     * _gid 缺省首元素为 int ⇒ 逐值 intval；name 缺省为 '' ⇒ 逐值 htmlspecialchars），
     * 以行序号 rowId 为键产出行定义：
     *  - rowId 命中现有组 ⇒ {gid:rowId, name, exists:TRUE}（更新）；
     *  - 否则按提交的 _gid[rowId] 建新组 ⇒ {gid:targetGid, name, exists:FALSE}（创建），
     *    targetGid<=0 或与他组/本批前序新行撞 gid 即 message 终止（防覆盖既有条目）。
     * _gid/name 任一非数组（伪造标量入参）⇒ 返回空数组（本批零保存，删除闸仍会执行）。
     * @param array $grouplist 现有组行集（gid 为键），用于 exists 判定
     * @return array gid 为键的 {gid,name,exists} 行集
     */
    private static function groupBatchPayload(array $grouplist): array
    {
        $gidarr = param('_gid', array(0));
        $namearr = param('name', array(''));
        if (!is_array($gidarr) || !is_array($namearr)) {
            return array();
        }

        $rows = array();
        foreach ($gidarr as $rowId => $postedGid) {
            $rowId = intval($rowId);
            $name = trim((string)array_value($namearr, $rowId, ''));
            $name === '' and message(1, lang('group_name_empty'));

            if (isset($grouplist[$rowId])) {
                $rows[$rowId] = array(
                    'gid' => $rowId,
                    'name' => $name,
                    'exists' => TRUE,
                );
                continue;
            }

            $targetGid = intval($postedGid);
            $targetGid <= 0 and message(1, lang('parameter_error'));
            if (isset($rows[$targetGid]) || isset($grouplist[$targetGid])) {
                message(1, lang('save_failed'));
            }
            $rows[$targetGid] = array(
                'gid' => $targetGid,
                'name' => $name,
                'exists' => FALSE,
            );
        }
        return $rows;
    }

    /**
     * 本服务写接口的 safe_token 统一校验入口：从 param 取 token 后交
     * admin_bull_token_guard（绑定 uid+场景指纹环，fail-closed——校验失败直接 message
     * 终止请求，本函数不返回布尔，调用方无需判断）。
     * @param int $uid 操作者 uid
     * @param string $scene token 场景名（与各 Page 签发时一致，如 admin_create_user）
     * @return void
     */
    private static function verifyToken(int $uid, string $scene = ''): void
    {
        $safeToken = param('safe_token');
        admin_bull_token_guard($uid, $scene, $safeToken);
    }
}
