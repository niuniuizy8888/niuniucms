<?php
/**
 * @deprecated 兼容壳 — 全部函数转发到 \App\Service\Content\CateService 与
 * \App\Repository\RepositoryFactory::cate()，新代码禁止调用（§二.2），请直接用 Service/Repository。
 * ⚠ 本壳与 Service 的差别不止「多一层转发」：部分函数**不失效分类列表/别名缓存**
 *   （cate_list_cache / alias_cache），后台与插件建改分类请走 CateService，否则前台导航可能读旧缓存。
 *   逐函数口径见下方各自 docblock（40 个函数全部已标注）。
 */
// hook model_cate_start.php
/**
 * @deprecated 兼容壳（文件头 hook 之后第一个函数，新代码禁止调用）：
 * 转发 RepositoryFactory::cate()->create($arr) —— create() 只是 insert() 的历史别名，
 * 直写 cate 表一行，**成功后会 bumpContentPageCache() 推进内容页缓存版本令牌**（整页缓存随之失效）。
 * ⚠ 与业务层 cate_create()/CateService 的差别：本壳**不失效分类列表/别名缓存**（cate_list_cache、
 *   alias_cache 一类），手工建分类后前台导航可能读旧缓存 ⇒ 后台建分类请走 Service。
 * 列名/值走 QueryBuilder 的标识符白名单与参数绑定（§二.3），不做 HTML 转义（转义责任在调用方，§二.4）。
 * @param array $arr 列名 => 值 的待插入行（须符合 cate 表列；空数组由底层短路）
 * @return int|false 新行 id 或影响数（底层 insert 语义）；失败 FALSE
 */
/**
 * @deprecated 兼容壳：转发 RepositoryFactory::cate()->update()，按 cid 条件直写 cate 表。
 * 与 cate_update() 的差异：不经 CateService，**不会失效分类列表/别名缓存**，只适合脚本式底层改写。
 * @param mixed $cid 分类 id（签名未声明类型、全仓无调用点可取证；cate 表主键，语义为 int）
 * @param array $arr 字段 => 值；列名可带 '+'/'-' 后缀做原子自增/自减（QueryBuilder::updateSql 语义）
 * @return int|false 受影响行数；$arr 为空时返回 FALSE
 */
/**
 * @deprecated 兼容壳：转发 RepositoryFactory::cate()->findOne()，直查 cate 表单行。
 * 与 cate_read() 的差异：不走全局 $catelist 缓存，也不做 format() 补 url/type_url 字段。
 * @param mixed $cid 分类 id（签名未声明类型、全仓无调用点可取证；语义为 int）
 * @return array|null 命中行（全列）；查不到返回 NULL
 */
/**
 * @deprecated 兼容壳：转发 RepositoryFactory::cate()->delete()，仅删 cate 表一行。
 * 与 cate_delete() 的差异：不级联删除频道内容、不回调父类 son-1、不失效缓存——业务删除别用本壳。
 * @param mixed $cid 分类 id（签名未声明类型、全仓无调用点可取证；语义为 int）
 * @return int|false 受影响行数（cond 非空恒走删除分支，FALSE 仅理论上可达）
 */
/**
 * @deprecated 兼容壳：转发 RepositoryFactory::cate()->find()，以 cid 为键返回。
 * 与 cate_find() 的差异：默认 orderby 为空（不保证 rank 排序），且无进程级 static 缓存。
 * @param array $cond 查询条件（QueryBuilder applyCond 语法）
 * @param array $orderby 排序：列 => 1(ASC)/-1(DESC)
 * @param int $page 页码（从 1 起）
 * @param int $pagesize 每页条数（默认 1000）
 * @return array<int, array> cid => 分类行；无命中或查询失败为空数组
 */
/**
 * @deprecated 兼容壳：转发 RepositoryFactory::cate()->bigInsert()（单条多值 INSERT，QueryBuilder::bulkInsert）。
 * 列集合取第一行键名，缺列的行以 NULL 补齐；不失效任何缓存。
 * @param array $arr 行列表（array of array）
 * @param mixed $d 连接/从库选择器（NULL=默认连接，透传给 builder()）
 * @return int|false 插入行数（bulkInsert 失败为 0）；$arr 为空返回 FALSE
 */
/**
 * @deprecated 兼容壳：转发 RepositoryFactory::cate()->bigUpdate()：取 $cond 的首个键作主键列名，
 * 用 CASE WHEN 按主键值分组批量更新（QueryBuilder::bulkUpdate）。不失效缓存。
 * @param array $cond 仅第一个键生效（如 array('cid' => null)，条件列名=cid）
 * @param array $update 主键值 => array(字段 => 值, 'views+' => n)；非数组的分组会被跳过
 * @param mixed $d 连接/从库选择器
 * @return int|false 受影响行数；$cond/$update 为空返回 FALSE
 */
/**
 * @deprecated 兼容壳：转发 \App\Service\Content\CateService::create()——插入分类并失效分类列表/别名缓存。
 * 新代码请直接调 CateService。
 * @param array $arr 分类行数据（cid 为自增主键，可不传）
 * @return int|false 新行自增 id（写失败为 0）；$arr 为空返回 FALSE
 */
function cate_create($arr)
{
    // hook model_cate_cate_create_start.php

    // hook model_cate_cate_create_end.php
    return \App\Service\Content\CateService::create($arr);
}
/**
 * @deprecated 兼容壳：转发 CateService::update()（内部 intval($cid)）——按 cid 更新分类并失效分类缓存。
 * @param mixed $cid 分类 id（传入即被 intval）
 * @param array $arr 字段 => 值；列名 '+'/'-' 后缀走原子自增/自减（如 son+、videos+、arts+）
 * @return int|false 受影响行数；$arr 为空返回 FALSE
 */
function cate_update($cid, $arr)
{
    // hook model_cate_cate_update_start.php

    // hook model_cate_cate_update_end.php
    return \App\Service\Content\CateService::update(intval($cid), $arr);
}
/**
 * @deprecated 兼容壳：转发 CateService::read()（内部 intval($cid)）。
 * 缓存开启时命中全局 $catelist 直接返回（未命中给 array()）；缓存关闭时直查 DB 并 format() 补 url 字段。
 * ⚠ 列表口径不含 `content` 大列（单页正文，见 CateService::findFmt）：需要正文的调用方
 * （后台分类编辑页、单页路由 route/page.php）必须显式传 $withContent=TRUE，否则返回行里没有 content 键。
 * @param mixed $cid 分类 id（传入即被 intval）
 * @param bool  $withContent TRUE=确保返回行带 content（缓存命中且缺列时补一次单行读取）
 * @return array|null 分类行；查不到为 array()（缓存开）或 NULL（缓存关，findOne 语义）
 */
function cate_read($cid, $withContent = FALSE)
{
    // hook model_cate_cate_read_start.php

    // hook model_cate_cate_read_end.php
    return \App\Service\Content\CateService::read(intval($cid), (bool)$withContent);
}
/**
 * @deprecated 兼容壳：转发 CateService::delete()（内部 intval($cid)）。
 * 级联语义：type=1 频道分批（每批 5000）删除其下视频、父类 son-1、最后失效分类缓存；
 * fail-closed——$cid 为空或不在全局 $catelist 时直接返回 FALSE，不碰数据库。
 * @param mixed $cid 分类 id（传入即被 intval）
 * @return int|false 删除的 cate 行受影响数；前置校验失败返回 FALSE
 */
function cate_delete($cid)
{
    // hook model_cate_cate_delete_start.php

    // hook model_cate_cate_delete_end.php
    return \App\Service\Content\CateService::delete(intval($cid));
}
/**
 * @deprecated 兼容壳：转发 CateService::find()——同参数组合在进程级 static 缓存内只查一次库。
 * @param array $cond 查询条件（QueryBuilder applyCond 语法）
 * @param array $orderby 排序，默认 rank 降序
 * @param int $page 页码（从 1 起）
 * @param int $pagesize 每页条数（默认 1000）
 * @return array<int, array> cid => 分类行；无命中为空数组
 */
/**
 * @deprecated 兼容壳：转发 CateService::findFmt()——在 find() 基础上逐行 format()，
 * 补 create_date_fmt/url/type_url 展示字段（模板直出前确认转义边界，见 CLAUDE.md「安全红线·转义分层」）。
 * @param array $cond 查询条件
 * @param array $orderby 排序，默认 rank 降序
 * @param int $page 页码
 * @param int $pagesize 每页条数（默认 1000）
 * @return array<int, array> cid => 已格式化分类行；无命中为空数组
 */
/**
 * @deprecated 兼容壳：转发 CateService::format(&$cate)——**引用形参就地改写**分类行：
 * 补 create_date_fmt、按 category/别名/伪静态开关生成 url、补 type_url；$cate 为空时不改写直接返回。
 * @param array $cate 分类行（引用传入）
 * @return null 转发目标无显式返回值
 */
/**
 * @deprecated 兼容壳：转发 CateService::count()（QueryBuilder::count，参数全绑定）。
 * @param array $cond 查询条件；空数组=全表计数
 * @return int 符合条件的分类数（查询失败按 0 计，见 QueryBuilder::count）
 */
/**
 * @deprecated 兼容壳：转发 CateService::maxId()（SELECT MAX(cid)）。
 * @return int 最大分类 id；表空或查询失败为 0
 */
/**
 * @deprecated 兼容壳：转发 CateService::listCache()——全量已格式化分类列表，
 * 进程级 static + 跨进程缓存（非站群域名场景写 catelist 键，TTL 7200s）。
 * 引导层（index.inc.php）用它初始化全局 $catelist。
 * @return array|null cid => 分类行；缓存缺失且回源结果非数组时返回 NULL
 */
function cate_list_cache()
{
    // hook model_cate_cate_list_cache_start.php

    // hook model_cate_cate_list_cache_end.php
    return \App\Service\Content\CateService::listCache();
}
/**
 * @deprecated 兼容壳：转发 CateService::deleteCache()——删除 catelist / cate-alias-cache 两个缓存键。
 * 目标带进程级已删标志：同一请求内多次调用只有首次真正删（分类增删改后按需再补）。无返回值。
 */
function cate_list_cache_delete()
{
    // hook model_cate_cate_list_cache_delete_start.php

    \App\Service\Content\CateService::deleteCache();
    // hook model_cate_cate_list_cache_delete_end.php
}
/**
 * @deprecated 兼容壳：转发 CateService::safeInfo()——当前实现为**原样透传**（历史上做脱敏，现已无处理逻辑），
 * 全仓亦无调用方。注意：本壳不做任何 XSS 过滤，勿当安全函数使用。
 * @param mixed $cate 分类行或列表（签名未声明类型，原样返回）
 * @return mixed 与入参相同
 */
/**
 * @deprecated 兼容壳：转发 CateService::filter()——剔除每行 seo_des/seo_title/seo_key/create_date_fmt 后返回列表。
 * @param array $catelist 分类列表（转发目标内部引用遍历，返回值即过滤后的同一数组）
 * @return array 过滤掉 SEO 冗余字段的分类列表
 */
/**
 * @deprecated 兼容壳：转发 CateService::formatUrl()——按 category 生成 URL：
 * 0=列表页、1=栏目页、2=单页（page- 前缀，取 seo_des，需调用方保证已转义）。
 * @param array $cate 分类行（需含 category/cid；category=2 时另需 seo_des）
 * @return string|null URL；category 取 0/1/2 之外的值时转发目标内 $url 未定义，返回 NULL（并触发告警）
 */
/**
 * @deprecated 兼容壳：转发 CateService::aliasMap()——基于全局 $catelist 构建 alias => cid 映射，
 * 重复别名 first-wins 并写 cate_error 日志（审计修复项）。
 * @return array|string 映射表；全局 $catelist 为空时返回空字符串 ''（非数组，调用方需判型）
 */
/**
 * @deprecated 兼容壳：转发 CateService::aliasCache()——aliasMap() 的进程级 static + 跨进程缓存版
 * （键 cate-alias-cache）。index.inc.php 的别名路由解析是唯一调用方。
 * @return array|string alias => cid 映射；无别名分类时返回空字符串 ''（非数组，调用方需判型）
 */
function cate_alias_cache()
{
    // hook model_cate_cate_alias_cache_start.php

    // hook model_cate_cate_alias_cache_end.php
    return \App\Service\Content\CateService::aliasCache();
}
/**
 * @deprecated 兼容壳：转发 CateService::categoryTree($catelist)（原注释「获取 CMS 全部栏目，
 * 包括频道的二叉树结构」）——取 type==1 的启用栏目，categoryTreeFormat 按 cup 把子栏目挂进
 * 父节点的 sonlist（**挂不上父级的不合格结构会被舍弃**），最后 array_multisort_key 按 rank 降序、以 cid 为键。
 * 数据来自**入参**（引导层预载的 $catelist），函数自身不查库；服务层有进程级缓存
 * （self::$categoryTreeCache，**只按「第一次调用」的结果缓存**，与入参内容无关）⇒
 * 同进程内换一份 $catelist 再调拿到的还是旧树，改了分类要清缓存/重发请求才生效。
 * @param array $catelist 全部栏目平表（cid 为键）
 * @return array|NULL 树形栏目结构（cid 为键、rank 降序，子级在 sonlist）；$catelist 为空 = NULL
 */
function category_tree($catelist)
{
    // hook model_cate_category_tree_start.php

    // hook model_cate_category_tree_end.php
    return \App\Service\Content\CateService::categoryTree($catelist);
}
/**
 * @deprecated 兼容壳：转发 CateService::channelCategory($cid)。
 * 原注释「门户：获取需要在频道显示的栏目 cid / name / index_new 最新显示数量」——
 * 服务层读 global $catelist_show（引导层预载的「可显示栏目」平表），命中 static 缓存直接回；
 * 条件是该频道**自身有 son 标记**，然后从平表里筛 `cup == 本频道 且 type==1 且 category==0`
 * 的子栏目按 cid 降序取一页（上限 1000）。
 * ⚠ 三处会返回 NULL（调用方必须判空，别当空数组用）：$catelist_show 里没有该 cid；
 *   该频道没有 son；以及 static 里存的就是 NULL（同一 cid 每进程只算一次）。
 * 数据全来自内存平表 ⇒ 不发 SQL；引导层没预载 $catelist_show 时结果为 NULL。
 * @param int|string $cid 频道分类 ID（直接进 static 缓存键与筛选条件）
 * @return array|NULL 子栏目行数组（cid 降序）；无频道/无子栏目 = NULL
 */
/**
 * @deprecated 兼容壳：转发 CateService::allChannel($catelist)（原注释「返回网站所有频道」）。
 * 服务层从入参平表里筛 `type==1 且 category==1`（启用 + 频道形态）取一页（**上限 100 个频道**），
 * 再压成 `cid => name` 的映射，并固定补一个键 '0' => lang('first_level_cate')（「一级分类」占位，
 * 供后台/模板的下拉选项直接遍历本结果而无需另加一项）。
 * 有进程级缓存 self::$allChannelCache ⇒ **只认第一次调用的入参**，改分类后同进程不会重算；
 * 全程不查库，数据是否新鲜取决于引导层预载的 $catelist。
 * @param array $catelist 全部栏目平表（cid 为键，通常传 cate_list_cache()/global $catelist）
 * @return array|NULL cid => 频道名 的映射（含键 '0' 的占位项）；$catelist 为空 = NULL
 */
/**
 * @param $catelist    所有版块列表
 * @return mixed    返回二叉树结构的版块列表
 */
function category_tree_format($catelist)
{
    // hook model_cate_category_tree_format_start.php

    // hook model_cate_category_tree_format_end.php
    return \App\Service\Content\CateService::categoryTreeFormat($catelist);
}
/**
 * @deprecated 兼容壳：转发 CateService::allCategory()——过滤 type=1 且 category<2 的频道栏目
 * （进程级缓存；仅条件过滤，**不构建树**，树结构在 category_tree 系列）。
 * @param array $catelist 全量分类列表（通常传 cate_list_cache() 结果）
 * @return array|null 过滤结果（保留原 cid 键）；$catelist 为空时 NULL
 */
/**
 * @deprecated 兼容壳：转发 CateService::categoryList()——过滤 type=1 且 category==$category 的频道；
 * $display=1 时再要求 display==1。注意：$model 形参**不参与过滤**，只作为进程级缓存键的一部分。
 * 后台三个 ManageService 用它装配分类下拉。
 * @param array $catelist 全量分类列表
 * @param int $model 内容模型值（仅进缓存键，源码中无过滤用途）
 * @param int $display 1=仅显示条目（display=1）
 * @param int $category 匹配的 category 字段值（0=列表 1=频道页 2=单页）
 * @return array|null 过滤结果（保留原键）；空输入或无命中返回 NULL
 */
function category_list($catelist, $model = 0, $display = 0, $category = 0)
{
    // hook model_cate_category_list_start.php

    // hook model_cate_category_list_end.php
    return \App\Service\Content\CateService::categoryList($catelist, $model, $display, $category);
}
/**
 * @deprecated 兼容壳：转发 CateService::categoryListShow()——过滤 type=1 且 category==$category 的频道；
 * $display=1 时再要求 nav_display==1（导航可见）。门户首页/频道页装配子栏目用。
 * @param array $catelist 全量分类列表
 * @param int $display 1=仅导航显示条目（nav_display=1）
 * @param int $category 匹配的 category 字段值
 * @return array|null 过滤结果（保留原键）；空输入或无命中返回 NULL
 */
/**
 * @deprecated 兼容壳：转发 CateService::cateList()——过滤 type=0 的版块（进程级缓存）。
 * @param array $catelist 全量分类列表
 * @return array<int, array> cid => 版块行；$catelist 为空时为空数组
 */
function cate_list($catelist)
{
    // hook model_cate_cate_list_start.php

    // hook model_cate_cate_list_end.php
    return \App\Service\Content\CateService::cateList($catelist);
}
/**
 * @deprecated 兼容壳：转发 CateService::navList()——剔除 nav_display=0 的条目（进程级缓存）。
 * @param array $catelist 全量分类列表
 * @return array|null 导航用列表；$catelist 为空时 NULL
 */
/**
 * @deprecated 兼容壳：转发 CateService::indexVideoCache()——indexVideo() 的进程级 + 跨进程缓存版（TTL 600s）。
 * @param array $catelist 全量分类列表
 * @param int $num 每栏目最新条数（参与缓存键 index_video_<num>）
 * @return array|null 首页视频装配数据；空结果缓存层归一为 NULL
 */
/**
 * @deprecated 兼容壳：转发 CateService::indexVideo()——装配首页「导航显示频道各取最新 N 条 + 全站置顶」：
 * 分类 TOP-N 走单条 UNION ALL 批量查询（性能审计 P-2），再经 VideoService::findAsc 回表补详情。
 * @param array $catelist 全量分类列表
 * @param int $num 每栏目最新条数
 * @return array|null array('list'=>cid=>栏目行+news, 'sticky'=>vid=>行)；$catelist 为空时 NULL
 */
/**
 * @deprecated 兼容壳：转发 CateService::channelVideoCache()——channelVideo() 的进程级 + 跨进程缓存版（TTL 1200s）。
 * 前台 CategoryService 已有直调 Service 的版本，新代码勿用本壳。
 * @param mixed $cid 频道分类 id（转发目标未声明类型，空值返回 NULL）
 * @param int $pagesize 每子栏目取条数（参与缓存键）
 * @return array|null 频道页视频装配数据；空结果缓存层归一为 NULL
 */
/**
 * @deprecated 兼容壳：转发 CateService::channelArtCache()——channelArt() 的进程级 + 跨进程缓存版（TTL 1200s）。
 * 前台 CategoryService 已有直调 Service 的版本，新代码勿用本壳。
 * @param mixed $cid 频道分类 id（转发目标未声明类型，空值返回 NULL）
 * @param int $pagesize 每子栏目取条数（参与缓存键）
 * @return array|null 频道页文章装配数据；空结果缓存层归一为 NULL
 */
/**
 * @deprecated 兼容壳：转发 CateService::channelArt()——门户频道页装配：$cid 下各子栏目取最新 $pagesize 篇文章
 * （UNION ALL 批量取 TOP-N + ArtService::findAsc 回表，P-2）。
 * @param mixed $cid 频道分类 id（空值直接返回 NULL）
 * @param int $pagesize 每子栏目条数
 * @return array|null array('list'=>cid=>栏目行+news)；$cid 为空时 NULL
 */
 
/**
 * @deprecated 兼容壳：转发 CateService::channelVideo()——门户频道页装配：$cid 下各子栏目取最新 $pagesize 条视频
 * + 该频道置顶（VideoService::stickyListMap），TOP-N 走 UNION ALL（P-2）。
 * @param mixed $cid 频道分类 id（空值直接返回 NULL）
 * @param int $pagesize 每子栏目条数
 * @return array|null array('list'=>cid=>栏目行+news, 'sticky'=>vid=>行)；$cid 为空时 NULL
 */
 
/**
 * @deprecated 兼容壳：转发 CateService::accessUser()——判断用户组对分类是否持有指定权限键。
 * fail-closed：$cid 不在全局 $catelist 时直接 FALSE；栏目开启 accesson 时需「组放行 且 栏目白名单含该组」同时成立。
 * @param mixed $cid 分类 id（调用点 ListAccessFilterTrait 传列表行的 cid）
 * @param mixed $gid 用户组 id
 * @param string $access 权限键（转发目标声明 string，现用调用点传 'allowread'）
 * @return bool 有权限 TRUE
 */
function cate_access_user($cid, $gid, $access)
{
    // hook model_cate_cate_access_user_start.php

    // hook model_cate_cate_access_user_end.php
    return \App\Service\Content\CateService::accessUser($cid, $gid, $access);
}
/**
 * @deprecated 兼容壳：转发 CateService::findCate()——在分类列表中找第一个 model==$model（宽松比较）
 * 且 url 非空的条目并取其 name/url；未命中或 $data 非数组时回退默认值。
 * index.inc.php 用它装配站点级 $nncms['v']（视频）/$nncms['a']（文章）。
 * @param array $data 分类列表（元素需含 model/url/name 三键）
 * @param mixed $model 内容模型值（宽松 == 比较）
 * @param string $defaultUrl 未命中时的回退 URL（默认 '/'）
 * @param string $defaultName 未命中时的回退名称（默认 '默认'）
 * @return array 含 name/url 两键的数组
 */
function findcate($data, $model, $defaultUrl = '/', $defaultName = '默认') {
    // hook model_cate_findcate_start.php

    // hook model_cate_findcate_end.php
    return \App\Service\Content\CateService::findCate($data, $model, $defaultUrl, $defaultName);
}

