<?php
namespace App\Service\Admin;

class CategoryManageService
{
    /**
     * 分类管理列表页数据装配：二叉树列表 + 三个 CSRF 场景 token + 「清空分类」爆炸半径统计。
     * 调用方 core/admin/route/cate.php（组权限位 managecate，GET 分支），结果以 extract(..., EXTR_SKIP) 进模板。
     * @param array $catelist 全量分类列表（cate_list_cache() 产物）
     * @param int $uid 当前管理员 uid（token 场景指纹绑定对象）
     * @return array{arrlist, safe_token, import_token, del_token, cate_total, vod_total, art_total}
     */
    public static function listPage(array $catelist, int $uid): array
    {
        return array(
            'arrlist' => category_tree($catelist),
            'safe_token' => bull_token_set($uid, 'admin_cate'),
            // 导入/清空分类为独立 POST 场景，需单独生成场景 token
            'import_token' => bull_token_set($uid, 'admin_api_import'),
            'del_token' => bull_token_set($uid, 'admin_api_catedel'),
            // 「清空分类」确认框要报出真实爆炸半径（栏目数 / 挂着的内容数）
            'cate_total' => \App\Repository\RepositoryFactory::cate()->count(),
            'vod_total' => \App\Repository\RepositoryFactory::vod()->count(),
            'art_total' => \App\Repository\RepositoryFactory::art()->count(),
        );
    }

    /**
     * 分类编辑页数据装配：读取分类行并 array_htmlspecialchars 后输出表单字段 + 父级候选树 + safe_token。
     * 分类不存在时 message(-1) 中断（fail-closed）。调用方 cate.php case 'update' GET 分支。
     * 注意：分类名入库前已转义，此处展示再转一次——存量特殊字符名存在双重转义观感（已登记可疑点）。
     * @param int $cid 分类 id
     * @param array $catelist 全量分类列表
     * @param int $uid 当前管理员 uid
     * @return array 模板变量表（_cid/arrlist/name/.../safe_token）
     */
    public static function editPage(int $cid, array $catelist, int $uid): array
    {
        // $withContent=TRUE：编辑页必须拿到单页正文，否则表单正文为空，保存即清空原有内容（数据丢失）。
        // 列表/全局 catelist 口径刻意不含 content 大列（见 CateService::findFmt）。
        $_cate = cate_read($cid, TRUE);
        empty($_cate) and message(-1, lang('cate_not_exists'));

        array_htmlspecialchars($_cate);
        return array(
            '_cid' => $cid,
            'arrlist' => category_tree($catelist),
            'name' => $_cate['name'],
            'seo_title' => $_cate['seo_title'],
            'seo_key' => $_cate['seo_key'],
            'seo_des' => $_cate['seo_des'],
            'content' => $_cate['content'] ?? '',
            'cate_type' => (2 === intval($_cate['category'] ?? 0)) ? 2 : 1,
            'alias' => $_cate['alias'],
            'category' => $_cate['category'],
            'cup' => $_cate['cup'],
            'model' => $_cate['model'],
            'nav_display' => $_cate['nav_display'],
            'display' => $_cate['display'],
            'safe_token' => bull_token_set($uid, 'admin_cate'),
        );
    }

    /**
     * 批量保存分类排序（拖拽提交）：以 cid 数组的「键」为分类 id、rank 数组同键值为其排序，逐条 cate_update。
     * POST + safe_token 场景 'admin_cate' + 组权限位 managecate（route/cate.php）三重门禁；fail-closed。
     * @param int $uid 当前管理员 uid
     * @return array<int> 本次提交过的 cid 列表（未校验存在性；审计日志 cate_rank 记同一列表）
     */
    public static function rank(int $uid): array
    {
        self::verifyAdminToken($uid);

        $cidarr = param('cid', array());
        $rankarr = param('rank', array());
        if (!is_array($cidarr)) $cidarr = array();
        if (!is_array($rankarr)) $rankarr = array();

        $changed = array();
        foreach ($cidarr as $k => $v) {
            $cid = intval($k);
            $rank = intval(array_value($rankarr, $k));
            cate_update($cid, array('rank' => $rank));
            $changed[] = $cid;
        }

        admin_audit_log('cate_rank', array('cids' => $changed));
        return $changed;
    }

    /**
     * 新建分类：取参/校验/转义统一走 adminPayload（内含 CSRF 复核）→ cate_create 写库，
     * 挂了父级时父类 son+1 原子自增；失效缓存由 CateService::create 内部完成。
     * @param int $uid 当前管理员 uid
     * @return int 新分类 cid（0=插入失败）
     */
    public static function create(int $uid): int
    {
        $payload = self::adminPayload($uid);
        $cid = cate_create($payload);
        // ⚠ 写失败 fail-closed（复核修正）：旧库缺 `content` 列时 INSERT 会 1054，被 prepare_execute
        //   吞成「0 行」⇒ 旧实现照样回 0，路由层再报「分类创建成功」。现在显式中断。
        if (empty($cid)) {
            message(-1, lang('create_failed'));
        }
        !empty($payload['cup']) and cate_update(intval($payload['cup']), array('son+' => 1));
        admin_audit_log('cate_create', array('name' => $payload['name'], 'cid' => $cid));
        return intval($cid);
    }

    /**
     * 编辑分类：cate_read 校验存在（fail-closed）→ adminPayload（$currentCid 允许别名自用）→ cate_update；
     * 父级变更时旧父 son-1、新父 son+1。create_date 不在 payload 内（update 不重置创建时间）。
     * @param int $uid 当前管理员 uid
     * @return int 被编辑的分类 cid
     */
    public static function update(int $uid): int
    {
        $cid = admin_param_int('cid', 0, 0);
        $cate = cate_read($cid);
        empty($cate) and message(-1, lang('cate_not_exists'));

        $payload = self::adminPayload($uid, $cid);
        $oldCup = intval($cate['cup']);
        $newCup = intval($payload['cup']);

        // ⚠ 写失败 fail-closed（复核修正）：0 行影响（列缺失→1054 被吞、或数据未变）不再当成功——
        //   前者必须让管理员看到失败，否则「编辑成功」但正文/SEO 一个字都没改。
        //   注：影响行数为 0 也可能是「提交内容与库内完全一致」（MySQL 语义），故只在**列缺失类**
        //   失败上中断：这里用 FALSE（语句失败）与「写入后回读校验」两条判据。
        $affected = cate_update($cid, $payload);
        if (false === $affected) {
            message(-1, lang('save_failed'));
        }
        // ⚠ 写入回读校验（复核修正）：旧库缺 `content` 列时 SET 触发 1054，被 prepare_execute 吞成
        //   「0 行影响」⇒ 只看返回值会把「一个字没改」报成编辑成功。这里对 content 直查 DB 回读比对
        //   （findOne 不走 $catelist 缓存，避免读到陈旧行造成误判）。仅单页/带 content 的提交才校验。
        if (array_key_exists('content', $payload)) {
            $verify = \App\Repository\RepositoryFactory::cate()->findOne(array('cid' => $cid));
            if (!is_array($verify) || !array_key_exists('content', $verify)
                || (string)($verify['content'] ?? '') !== (string)$payload['content']) {
                xn_log('cate update verify failed: cid=' . $cid . '（多为存量库缺 cate.content 列，需执行 .skills/deploy/schema-migrate-cate-recycle.sql）', 'cate_error');
                message(-1, lang('save_failed'));
            }
        }
        if ($oldCup !== $newCup) {
            !empty($oldCup) and cate_update($oldCup, array('son-' => 1));
            !empty($newCup) and cate_update($newCup, array('son+' => 1));
        }

        admin_audit_log('cate_update', array('cid' => $cid, 'name' => $payload['name']));
        return $cid;
    }

    /**
     * 删除分类（POST + safe_token 'admin_cate' + managecate 门禁）。
     * 前置守卫：分类下有视频（video_vid_find_by_cid 取样）或有子分类（son>0）即 message(-1) 拒删；
     * 通过后清 vod/vodVid 中该 cid 的行（守卫下通常为空，属纵深防御）、cate_delete 删行并失效缓存，
     * 再剔除采集绑定 $conf['bind'] 中该分类名的映射并 kv_set 落盘。
     * 注意：守卫只查视频，文章类栏目挂文不被拦截（已登记可疑点，见批次汇报）。
     * @param int $uid 当前管理员 uid
     * @param array $conf 站点配置（读 bind 键；调用方传 route/cate.php 的 $conf）
     * @return int 被删除的分类 cid
     */
    public static function delete(int $uid, array $conf): int
    {
        self::verifyAdminToken($uid);

        $cid = admin_param_int('cid', 0, 0);
        $cate = cate_read($cid);
        empty($cate) and message(-1, lang('cate_not_exists'));

        $videolist = video_vid_find_by_cid($cid, 1, 20);
        empty($videolist) || message(-1, lang('cate_delete_video_before_delete_cate'));
        !empty($cate['son']) and message(-1, lang('cate_please_delete_sub_cate'));

        \App\Repository\RepositoryFactory::vod()->delete(array('cid' => $cid));
        \App\Repository\RepositoryFactory::vodVid()->delete(array('cid' => $cid));
        // 复核修正：底部分类删除失败（语句失败/行已被并发删除）不再静默当成功
        if (FALSE === cate_delete($cid)) {
            message(-1, lang('save_failed'));
        }

        $conf['bind'] = self::removeBindByCateName($cate['name'], isset($conf['bind']) && is_array($conf['bind']) ? $conf['bind'] : array());
        kv_set('bind', $conf['bind']);

        admin_audit_log('cate_del', array('cid' => $cid, 'name' => $cate['name']));
        return $cid;
    }

    /**
     * 采集向导「新建分类」AJAX 分支（route/api.php case 'newcreate'：POST + admin_require_bull_token('admin_api_newcreate') + 组权限位 manageapi）。
     * 名称经 filter_all_html 判空后，按原名/转义名双查 cate_list_cache() 查重（兼容存量未转义名），
     * 不存在才 createCategory 落库。$payload 各键由路由层从请求参数装配。
     * @param array $payload type_name/seo_des/model/category/fup/display 等建类字段
     * @return array{success:bool, msg:string} success=false 表示分类名已存在（lang key cate_not_exist 的文案即「分类已存在」）
     */
    public static function createAjax(array $payload): array
    {
        $name = filter_all_html($payload['type_name'] ?? '');
        empty($name) and message(1, lang('data_empty_to_last_step'));

        $cate_arr = arrlist_key_values(cate_list_cache(), 'name', 'cid');
        // 分类名统一转义入库（与 buildCategoryTree 同口径），查重按原名/转义名双查兼容存量
        $name_esc = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        if (isset($cate_arr[$name]) || isset($cate_arr[$name_esc])) {
            return array('success' => false, 'msg' => lang('cate_not_exist'));
        }

        self::createCategory($payload, $name);
        admin_audit_log('api_newcreate', array('code' => $payload['code'] ?? '', 'name' => $name));
        return array('success' => true, 'msg' => '创建成功');
    }

    /**
     * 视频采集「建分类并自动绑定」（route/api.php case 'create'：POST + token 场景 admin_api_create + manageapi）。
     * createCategory 建类后，ApiBindService::autoBindAll 把源站 type_name 批量映射到本地分类。
     * @param array $payload name/api/code 及建类字段（路由层装配）
     * @return array{success:bool, msg:string}
     */
    public static function createVideo(array $payload): array
    {
        $name = filter_all_html($payload['name'] ?? '');
        empty($name) and message(1, lang('data_empty_to_last_step'));
        self::createCategory($payload, $name);
        \App\Service\Collection\ApiBindService::autoBindAll($payload['code'] ?? '', $payload['api'] ?? '');
        admin_audit_log('api_create', array('code' => $payload['code'] ?? '', 'name' => $name));
        return array('success' => true, 'msg' => '分类创建成功');
    }

    /**
     * 文章采集「建分类并自动绑定」（route/api.php case 'art_create'：POST + token 场景 admin_api_art_create + manageapi）。
     * 与 createVideo 同构，自动绑定改走 ApiArtService::autoBindAll。
     * @param array $payload name/api/code 及建类字段（路由层装配；注意本分支路由用 param() 取原始值）
     * @return array{success:bool, msg:string}
     */
    public static function createArt(array $payload): array
    {
        $name = filter_all_html($payload['name'] ?? '');
        empty($name) and message(1, lang('data_empty_to_last_step'));
        self::createCategory($payload, $name);
        \App\Service\Collection\ApiArtService::autoBindAll($payload['code'] ?? '', $payload['api'] ?? '');
        admin_audit_log('api_art_create', array('code' => $payload['code'] ?? '', 'name' => $name));
        return array('success' => true, 'msg' => '分类创建成功');
    }

    /**
     * 统一建类内核（createAjax/createVideo/createArt 共用）——入库转义口径的落点：
     * name/seo_title/seo_key 走 filter_all_html + htmlspecialchars(ENT_QUOTES)，seo_des 走 xn_html_safe
     * （前台导航裸 echo 依赖此处，见 CLAUDE.md「安全红线·转义分层」）。
     * alias 由 uniq_id() 生成并校验保留字/重复；语义注意：$payload['category'] 非 0 时被重载为
     * **父频道 cid**（0 时父级取 fup），实际入库行 category 恒为 0（子栏目）。
     * 校验失败一律 message() 中断（fail-closed）。
     * @param array $payload 建类字段（model/nav_display/display/category/fup/seo_* 等）
     * @param string $name 已过 filter_all_html 的分类名
     * @return int 新分类 cid（RepositoryFactory::cate()->insert 直插 + 父级 son+1 + 失效列表缓存）
     */
    private static function createCategory(array $payload, string $name): int
    {
        $time = _SERVER('time');         global $alias_reservation;

        // 统一入库口径：分类名/SEO 短文本转义（filter_all_html 去标签但保留引号，meta content 与导航裸 echo 属性上下文会逃逸）
        $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $seo_des = xn_html_safe($payload['seo_des'] ?? '');
        $nav_display = intval($payload['nav_display'] ?? 0);
        $model = intval($payload['model'] ?? 0);
        $seo_title = htmlspecialchars(filter_all_html((string)($payload['seo_title'] ?? '')), ENT_QUOTES, 'UTF-8');
        $seo_key = htmlspecialchars(filter_all_html((string)($payload['seo_key'] ?? '')), ENT_QUOTES, 'UTF-8');
        $category = intval($payload['category'] ?? 0);
        $alias = uniq_id();
        if (0 == $category) {
            $cup = intval($payload['fup'] ?? 0);
            $display = 0;
        } else {
            $cup = $category;
            $display = intval($payload['display'] ?? 0);
        }

        !preg_match('#^[\w]*$#i', $alias) and message(1, lang('alias_tips'));
        // $alias_reservation 仅 index.inc.php/admin.func.php 初始化，CLI/API 入口兑底空数组
        $aliasReserved = (array)($GLOBALS['alias_reservation'] ?? array());
        in_array($alias, $aliasReserved) and message('alias', lang('alias_system_reservation'));
        $aliaslist = \App\Service\Content\CateService::aliasCache();
        $alias && !empty($aliaslist) && isset($aliaslist[$alias]) and message('alias', lang('alias_duplicate'));

        $arr = array(
            'cup' => $cup,
            'type' => 1,
            'model' => $model,
            'category' => 0,
            'name' => $name,
            'create_date' => $time,
            'display' => $display,
            'nav_display' => $nav_display,
            'seo_title' => $seo_title,
            'seo_key' => $seo_key,
            'seo_des' => $seo_des,
            'alias' => $alias,
        );
        $_fid = \App\Repository\RepositoryFactory::cate()->insert($arr);
        $cup and \App\Repository\RepositoryFactory::cate()->update(array('cid' => $cup), array('son+' => 1));
        cate_list_cache_delete();
        return intval($_fid);
    }

    /**
     * 后台增/改分类的统一取参与校验（CLAUDE.md「开发约定·后台参数与门禁」写操作三件套的 Service 侧落点）：
     * verifyAdminToken CSRF 复核 → admin_param_int/text 取参 → 入库转义（同 createCategory 口径）→
     * 父级存在性/自环检查 → alias 格式、保留字、重复校验（$currentCid>0 时允许别名自用自己）。
     * 有任一校验不过即 message() 中断，返回的 payload 必是合法可写形态。
     * @param int $uid 当前管理员 uid
     * @param int $currentCid 0=新建；>0=编辑该分类（影响 create_date 与 alias 自重校验）
     * @return array 可直接交给 cate_create/cate_update 的分类行
     */
    private static function adminPayload(int $uid, int $currentCid = 0): array
    {
        $time = _SERVER('time');         global $alias_reservation;

        self::verifyAdminToken($uid);

        // 与采集建类链统一「入库=转义」口径（filter_all_html 去标签但保留引号，属性上下文会逃逸）
        $name = htmlspecialchars(filter_all_html(admin_param_text('name')), ENT_QUOTES, 'UTF-8');
        empty($name) and message(1, lang('data_empty_to_last_step'));

        $seoDes = xn_html_safe(admin_param_text('seo_des', '', 500));
        $navDisplay = admin_param_int('nav_display', 0, 0, 1);
        $model = admin_param_int('model', 0, 0);
        $seoTitle = htmlspecialchars(filter_all_html(admin_param_text('seo_title', '', 255)), ENT_QUOTES, 'UTF-8');
        $seoKey = htmlspecialchars(filter_all_html(admin_param_text('seo_key', '', 255)), ENT_QUOTES, 'UTF-8');
        $parentCid = admin_param_int('category', 0, 0);
        $alias = filter_all_html(admin_param_text('alias', '', 50));
        // 栏目类型（审查修复轮 A4 单页实现）：1=频道（默认），2=单页。单页强制顶级、无子栏目，
        // 正文 content 从原始 param 取（保留换行/HTML，由 xn_html_safe 白名单收口）。
        $cateType = admin_param_int('cate_type', 1, 1, 2);
        $content = (2 === $cateType) ? xn_html_safe((string)param('content', '', FALSE)) : '';
        // ⚠ 单页 slug 校验（复核修正）：`page-<seo_des>` 是单页唯一入口，而 URL 前缀解析在各伪静态
        //   档位都会吞掉 `-`（rewrite 2/3 把 '-' 换成 '/'；0/1 按 '-' 分段）⇒ slug 含 `-`/空格/非 ASCII
        //   一律 404（子代理四档位矩阵实测）。故单页 slug 限定 [A-Za-z0-9_]、非空、且在 category=2
        //   集合内唯一（否则同 slug 的后者永不可达）。频道不限（slug 不参与其链接生成）。
        if (2 === $cateType) {
            if ('' === $seoDes) {
                message('seo_des', lang('seo_des_required'));
            }
            if (!preg_match('#^[A-Za-z0-9_]+$#', $seoDes)) {
                message('seo_des', lang('seo_des_invalid'));
            }
            foreach ((array)cate_list_cache() as $_c) {
                if (intval($_c['cid'] ?? 0) === $currentCid) continue;
                if (2 === intval($_c['category'] ?? 0) && (string)($_c['seo_des'] ?? '') === $seoDes) {
                    message('seo_des', lang('seo_des_duplicate'));
                }
            }
        }

        if (2 === $cateType) {
            $cup = 0;
            $display = 0;
            $category = 2;
        } elseif ($parentCid > 0) {
            $parent = cate_read($parentCid);
            empty($parent) and message(-1, lang('cate_not_exists'));
            $currentCid && $parentCid === $currentCid and message(1, lang('data_malformation'));
            $cup = $parentCid;
            $display = admin_param_int('display', 0, 0, 1);
            $category = 0;
        } else {
            $cup = 0;
            $display = 0;
            $category = 1;
        }

        !preg_match('#^[\w]*$#i', $alias) and message(1, lang('alias_tips'));
        $aliasReserved = (array)($GLOBALS['alias_reservation'] ?? array());
        in_array($alias, $aliasReserved) and message('alias', lang('alias_system_reservation'));
        $aliasList = \App\Service\Content\CateService::aliasCache();
        if ($alias && !empty($aliasList) && isset($aliasList[$alias]) && intval($aliasList[$alias]) !== $currentCid) {
            message('alias', lang('alias_duplicate'));
        }

        $payload = array(
            'cup' => $cup,
            'type' => 1,
            'model' => $model,
            'category' => $category,
            'name' => $name,
            'display' => $display,
            'nav_display' => $navDisplay,
            'alias' => $alias,
        );
        // ⚠ SEO 三键按「请求里是否真的带了该字段」条件入载荷（复核修正）：cate_post.html 此前**没有**
        //   seo_title/seo_key/seo_des 输入框，而旧写法无条件写这三个键 ⇒ 每次保存都把已存的 SEO 元数据
        //   清空（单页的 seo_des 被清空 ⇒ URL 恒为死链 `page-`，页面永不可达）。现模板已补输入框，
        //   同时此处做防御：非表单来源（API/插件/部分提交）不带该键就不动该列。
        array_key_exists('seo_title', $_REQUEST) and $payload['seo_title'] = $seoTitle;
        array_key_exists('seo_key', $_REQUEST) and $payload['seo_key'] = $seoKey;
        array_key_exists('seo_des', $_REQUEST) and $payload['seo_des'] = $seoDes;
        // ⚠ content 只在「单页」类型时进载荷（复核修正）：旧写法对频道也写 `content => ''`，
        //   于是把单页改成频道（表单里正文框被隐藏、提交空值）会**直接把正文清空**，
        //   再改回单页时内容已丢。现在类型≠2 时不带该键 ⇒ UPDATE 不动 content 列（INSERT 用列默认）。
        //   单页要清空正文仍可：保持类型为单页、把正文框清空提交（走 '' 分支）。
        if (2 === $cateType) {
            $payload['content'] = $content;
        }
        if (0 === $currentCid) {
            $payload['create_date'] = $time;
        }
        return $payload;
    }

    /**
     * CSRF 门禁：校验请求参数 safe_token 属于会话场景指纹环 'admin_cate'（绑定 ip/uid/UA/时效）。
     * admin_bull_token_guard 失败即中断请求——fail-closed，不可改成放行。
     * @param int $uid 当前管理员 uid
     */
    private static function verifyAdminToken(int $uid): void
    {
        $safeToken = param('safe_token');
        admin_bull_token_guard($uid, 'admin_cate', $safeToken);
    }

    /**
     * 从采集绑定配置剔除已删分类：$bind 形态为 源编码 => [分类名 => cid]（conf['bind']），
     * 逐源删除该分类名映射，返回新数组（不修改入参 $bind）。
     * @param string $cateName 已删除分类的名称（库内转义形态）
     * @param array $bind 全量绑定配置
     * @return array 剔除后的绑定配置
     */
    private static function removeBindByCateName(string $cateName, array $bind): array
    {
        $replaces = array();
        foreach ($bind as $key => $value) {
            if (is_array($value) && isset($value[$cateName])) {
                unset($value[$cateName]);
            }
            $replaces[$key] = $value;
        }
        return $replaces;
    }
}
