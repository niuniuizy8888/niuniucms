<?php
/**
 * @deprecated 兼容壳 — tag__* 转发到 RepositoryFactory，tag_* 转发到 \App\Service\Content\TagService / TagFlowService
 * 新代码禁止调用，应直接使用 TagService 或 RepositoryFactory。
 */
// hook model_tag_start.php
function tag__create($arr = array(), $d = NULL)
{
    // hook model_tag_tag__create_start.php

    $r = \App\Repository\RepositoryFactory::tag()->insert($arr, $d);
    // hook model_tag_tag__create_end.php
    return $r;
}
/**
 * @deprecated 兼容壳 — 按条件更新 tag 表，转发 RepositoryFactory::tag()->update()。新代码用 TagService/RepositoryFactory。
 * 副作用：底层 DbTableRepository::update() 成功时调 bumpContentPageCache()（tag 在内容表白名单内）⇒ 推进整页缓存版本令牌。
 * @param array $cond   查询条件（列=>值；值传数组走 IN；列名非法会被 applyCond 静默跳过，条件清空后仓储层返回 FALSE）
 * @param array $update 列名=>值 更新集；列名以 + / - 结尾走该列原子自增/自减表达式
 * @param \PdoDriver|null $d 连接选择器，NULL=默认连接（db_table()），非 NULL 直连该库
 * @return int|FALSE 影响行数；$cond 或 $update 为空返回 FALSE
 */
function tag__update($cond = array(), $update = array(), $d = NULL)
{
    // hook model_tag_tag__update_start.php

    $r = \App\Repository\RepositoryFactory::tag()->update($cond, $update, $d);
    // hook model_tag_tag__update_end.php
    return $r;
}
/**
 * @deprecated 兼容壳 — 按条件读单行标签，转发 RepositoryFactory::tag()->findOne()。新代码用 TagService::readById()/readByName()。
 * @param array $cond    查询条件（列=>值；值传数组走 IN）
 * @param array $orderby 排序（列=>1 升序 / -1 降序）
 * @param array $col     列清单，空数组 = SELECT *（findOne 不做 sql_star_select 留痕，但 tag 表列窄、无大字段）
 * @param \PdoDriver|null $d 连接选择器，NULL=默认连接
 * @return array|null 行数组；未命中或查询失败均为 NULL（非 FALSE、非空数组）
 */
function tag__read($cond = array(), $orderby = array(), $col = array(), $d = NULL)
{
    // hook model_tag_tag__read_start.php

    $r = \App\Repository\RepositoryFactory::tag()->findOne($cond, $orderby, $col, $d);
    // hook model_tag_tag__read_end.php
    return $r;
}
/**
 * @deprecated 兼容壳 — 分页查标签列表，转发 RepositoryFactory::tag()->find()。新代码用 TagService::find()/RepositoryFactory::tag()。
 * @param array $cond     查询条件（列=>值；值传数组走 IN）
 * @param array $orderby  排序（列=>1 升序 / -1 降序）
 * @param int   $page     页码（从 1 起，底层 max(1,$page) 钳制）
 * @param int   $pagesize 每页条数
 * @param string $key     结果重建索引用的列名，默认 tagid；传空串则返回 0..n 顺序键
 * @param array $col      列清单，空数组 = SELECT * 并按 DEBUG 记 sql_star_select 留痕（审计 R10）
 * @param \PdoDriver|null $d 连接选择器，NULL=默认连接
 * @return array 以 $key 列值为键的行列表；无结果=空数组。⚠ 键为 vid/tagid 一类可重复列时后行覆盖前行
 */
function tag__find($cond = array(), $orderby = array(), $page = 1, $pagesize = 20, $key = 'tagid', $col = array(), $d = NULL)
{
    // hook model_tag_tag__find_start.php

    $arr = \App\Repository\RepositoryFactory::tag()->find($cond, $orderby, $page, $pagesize, $key, $col, $d);
    // hook model_tag_tag__find_end.php
    return $arr;
}
/**
 * @deprecated 兼容壳 — 按 tagid 删标签，转发 RepositoryFactory::tag()->delete(array('tagid'=>$tagid))。
 * 不清任何缓存（web_tag_* 失效在 TagService::delete() 一侧），也不动 tag_id 关联表。
 * @param mixed $tagid 标签主键（绑定值；NULL 会被 applyCond 转成 `tagid IS NULL`）
 * @param \PdoDriver|null $d 连接选择器，NULL=默认连接
 * @return int|FALSE 影响行数（0=无匹配）；底层仅在条件数组为空时返回 FALSE，本壳恒传非空条件
 */
function tag__delete($tagid, $d = NULL)
{
    // hook model_tag_tag__delete_start.php

    $r = \App\Repository\RepositoryFactory::tag()->delete(array('tagid' => $tagid), $d);
    // hook model_tag_tag__delete_end.php
    return $r;
}
/**
 * @deprecated 兼容壳 — 按条件统计标签数，转发 RepositoryFactory::tag()->count()（声明返回 int）。
 * @param array $cond 查询条件，空数组=全表
 * @param \PdoDriver|null $d 连接选择器，NULL=默认连接
 * @return int 命中行数（转发目标声明 int，查询失败也归 0）
 */
function tag__count($cond = array(), $d = NULL)
{
    // hook model_tag_tag__count_start.php

    $n = \App\Repository\RepositoryFactory::tag()->count($cond, $d);
    // hook model_tag_tag__count_end.php
    return $n;
}
/**
 * @deprecated 兼容壳 — 取 tag 表某列最大值（默认主键 tagid），转发 RepositoryFactory::tag()->maxId()。
 * @param string $col  列名；非 `^[A-Za-z_][A-Za-z0-9_]*$` 直接抛 InvalidArgumentException（QueryBuilder::maxId）
 * @param array  $cond 附加条件，空数组=全表
 * @param \PdoDriver|null $d 连接选择器，NULL=默认连接
 * @return int 最大值；表内无行/查询失败为 0（转发目标声明 int）
 */
/**
 * @deprecated 兼容壳 — 批量 INSERT 标签，转发 RepositoryFactory::tag()->bigInsert()（多行 VALUES、全参数化）。
 * 副作用：成功时推进整页缓存版本令牌（tag 在内容表白名单内）。列清单取 $arr 首行的键，缺列补 NULL。
 * @param array $arr 行列表 array(array(列=>值,...), ...)
 * @param \PdoDriver|null $d 连接选择器，NULL=默认连接
 * @return int|FALSE 插入行数；$arr 为空返回 FALSE
 */
/**
 * @deprecated 兼容壳 — 按主键 CASE WHEN 批量更新，转发 RepositoryFactory::tag()->bigUpdate()。
 * ⚠ 主键列名取 $cond 的**首个键**（其值被忽略），批量条件值来自 $update 的键；与 tag__update 的单条件语义不同。
 * @param array $cond   条件数组，仅第一个键有意义（如 array('tagid'=>...) ⇒ 按 tagid 批量更新）
 * @param array $update 批量更新集 array(主键值 => array(列=>值, 'count+'=>1), ...)；列名 +/- 后缀=原子自增/自减
 * @param \PdoDriver|null $d 连接选择器，NULL=默认连接
 * @return int|FALSE 影响行数；$cond 或 $update 为空返回 FALSE（主键列名非法 ⇒ bulkUpdate 返回 0）
 */
/**
 * @deprecated 兼容壳 — 写标签-视频关联，转发 RepositoryFactory::tagVideo()->replace()（库表 tag_id，MySQL REPLACE 语义）。
 * 副作用：成功时推进整页缓存版本令牌（tag_id 在内容表白名单内）。⚠ 新装库 tag_id 已带
 * UNIQUE uk_tagid_vid(tagid,vid)（install.sql）⇒ 不带 id 的 REPLACE 会按唯一键先删后插（真 upsert，
 * 绑定行 id 会换）；**存量库**未跑去重迁移前无该索引 ⇒ 同一 (tagid,vid) 可重复成行。
 * 当前全仓无调用点（写入链路统一走 tag_video_big_insert），属保留兼容面。
 * @param array $arr 关联行数据（列=>值）
 * @param \PdoDriver|null $d 连接选择器，NULL=默认连接
 * @return int|FALSE 自增 ID（无自增为 0）；$arr 为空返回 FALSE
 */
function tag_video_create($arr, $d = NULL)
{
    // hook model_tag_tag_video_create_start.php

    if (empty($arr)) return FALSE;
    $r = \App\Repository\RepositoryFactory::tagVideo()->replace($arr, $d);
    // hook model_tag_tag_video_create_end.php
    return $r;
}
/**
 * @deprecated 兼容壳 — 按条件更新标签关联表（tag_id），转发 RepositoryFactory::tagVideo()->update()。
 * 副作用：成功时推进整页缓存版本令牌；不清 TagFlowService::videoFind() 的 tag_vid_* 缓存（靠 600s TTL 收敛）。
 * @param array $cond   查询条件（列=>值；值传数组走 IN）
 * @param array $update 列名=>值 更新集；列名以 + / - 结尾走原子自增/自减
 * @param \PdoDriver|null $d 连接选择器，NULL=默认连接
 * @return int|FALSE 影响行数；$cond 或 $update 为空返回 FALSE
 */
/**
 * @deprecated 兼容壳 — 按关联行 id 删除，转发 RepositoryFactory::tagVideo()->delete(array('id'=>$id))。
 * 空 $id 先短路 FALSE（不发 SQL）；底层返回 FALSE 时原样透传，不执行 end 锚点之后的逻辑。
 * @param mixed $id 关联表主键 id（绑定值）
 * @param \PdoDriver|null $d 连接选择器，NULL=默认连接
 * @return int|FALSE 影响行数（0=无匹配）；$id 为空或底层删除失败为 FALSE
 */
/**
 * @deprecated 兼容壳 — 分页查标签关联行，转发 RepositoryFactory::tagVideo()->find()。
 * @param array $cond     查询条件（列=>值；值传数组走 IN，如 array('vid'=>$vid)）
 * @param array $orderby  排序（列=>1 升序 / -1 降序）
 * @param int   $page     页码（从 1 起）
 * @param int   $pagesize 每页条数
 * @param string $key     结果重建索引用的列名，默认 vid；传空串则返回顺序键列表
 * @param array $col      列清单，空数组 = SELECT *（tag_id 仅 id/tagid/vid 三列，代价可忽略）
 * @param \PdoDriver|null $d 连接选择器，NULL=默认连接
 * @return array 以 $key 列值为键的行列表；无结果=空数组。⚠ 默认键 vid：一个视频命中多标签时只剩一行，
 *         需要「一视频的全部标签」请传 $key=''
 */
function tag_video__find($cond = array(), $orderby = array(), $page = 1, $pagesize = 20, $key = 'vid', $col = array(), $d = NULL)
{
    // hook model_tag_tag_video__find_start.php

    $arr = \App\Repository\RepositoryFactory::tagVideo()->find($cond, $orderby, $page, $pagesize, $key, $col, $d);
    // hook model_tag_tag_video__find_end.php
    return $arr;
}
/**
 * @deprecated 兼容壳 — 按条件统计标签关联行数，转发 RepositoryFactory::tagVideo()->count()（声明返回 int）。
 * @param array $cond 查询条件，空数组=全表
 * @param \PdoDriver|null $d 连接选择器，NULL=默认连接
 * @return int 命中行数
 */
/**
 * @deprecated 兼容壳 — 取标签关联表某列最大值（默认主键 id），转发 RepositoryFactory::tagVideo()->maxId()。
 * @param string $col  列名；非 `^[A-Za-z_][A-Za-z0-9_]*$` 抛 InvalidArgumentException
 * @param array  $cond 附加条件，空数组=全表
 * @param \PdoDriver|null $d 连接选择器，NULL=默认连接
 * @return int 最大值；无行/查询失败为 0
 */
/**
 * @deprecated 兼容壳 — 批量写入标签关联行，转发 RepositoryFactory::tagVideo()->bigInsert()。
 * 这是采集/保存标签链路（TagFlowService::process）的实际写入点；副作用=成功时推进整页缓存版本令牌。
 * ⚠ tag_id 带 UNIQUE(tagid,vid)（新装库见 core/install/install.sql；存量库跑
 *   .skills/deploy/tag_id-dedupe-migrate.php 补）。因此本壳**默认走 INSERT IGNORE**：
 *   撞唯一键的行单独跳过，而不是让整条多值语句 1062 失败把同批其它标签一起丢掉
 *   （失败会被 prepare_execute 吞成 rowCount=0：异常不上抛、只写一条 db_error 日志留痕
  *    （实测形如 `errno=23000 … 1062 Duplicate entry '101-888' for key 'uk_tagid_vid'`），
  *    所以调用方拿不到任何错误信号）。
 *   调用方 TagFlowService::process() 仍会先按已绑定集合过滤 —— 那是为了让 tag.count 只在
 *   真正新增绑定时才 +1，两者职责不同，别以为可以只留一个。
 * @param array $arr 行列表 array(array('tagid'=>..,'vid'=>..), ...)（列清单取首行的键，缺列补 NULL）
 * @param \PdoDriver|null $d 连接选择器，NULL=默认连接
 * @param bool $ignore 是否 INSERT IGNORE，默认 TRUE（本表有唯一键）；传 FALSE 会退回"撞键即整批失败"
 * @return int|FALSE 实际插入行数（被跳过的不计入）；$arr 为空返回 FALSE
 */
function tag_video_big_insert($arr = array(), $d = NULL, $ignore = TRUE)
{
    // hook model_tag_tag_video_big_insert_start.php

    $r = \App\Repository\RepositoryFactory::tagVideo()->bigInsert($arr, $d, (bool) $ignore);
    // hook model_tag_tag_video_big_insert_end.php
    return $r;
}
/**
 * @deprecated 兼容壳 — 按主键批量更新标签关联行，转发 RepositoryFactory::tagVideo()->bigUpdate()。
 * ⚠ 主键列名取 $cond 的首个键（值被忽略），批量值取 $update 的键；语义同 tag_big_update。
 * @param array $cond   条件数组，仅第一个键有意义（一般为 array('id'=>...) ⇒ 按关联行 id 批量更新）
 * @param array $update array(主键值 => array(列=>值), ...)
 * @param \PdoDriver|null $d 连接选择器，NULL=默认连接
 * @return int|FALSE 影响行数；$cond 或 $update 为空返回 FALSE
 */
//--------------------------强相关--------------------------
function tag_create($arr)
{
    // hook model_tag_tag_create_start.php

    // hook model_tag_tag_create_end.php
    return \App\Service\Content\TagService::create($arr);
}
// 标签名查询
function tag_read_name($name)
{
    // hook model_tag_tag_read_name_start.php

    // hook model_tag_tag_read_name_end.php
    return \App\Service\Content\TagService::readByName($name);
}
// 标签tagid查询
/**
 * @deprecated 兼容壳 — 更新标签并失效 ID 维度缓存，转发 TagService::update()。新代码直接用 TagService。
 * @param mixed $tagid  标签主键或主键数组（数组走 IN 并逐 id 清键）
 * @param array $update 列名=>值 更新集（+/- 后缀走原子自增/自减）
 * @return int|FALSE 影响行数；入参为空或底层 tag__update 失败为 FALSE。注意 name 维度缓存
 *         web_tag_{md5(name)} 不清 ⇒ 改名后旧名键靠 1800s TTL 收敛
 */
function tag_update($tagid, $update)
{
    // hook model_tag_tag_update_start.php

    // hook model_tag_tag_update_end.php
    return \App\Service\Content\TagService::update($tagid, $update);
}
/**
 * @deprecated 兼容壳 — 标签分页列表（后台管理口径），转发 TagService::find()：逐行补 i 序号并 tag_format() 装配 url。
 * @param int  $page     页码（转发目标声明 int）
 * @param int  $pagesize 每页条数（转发目标声明 int）
 * @param bool $desc     TRUE=按 tagid 降序（新标签在前），FALSE=升序
 * @return array|null 以 tagid 为键的行列表；**无结果返回 NULL**（与 tag__find 的空数组不同，调用方需判空）
 */
/**
 * @deprecated 兼容壳 — 按 tagid 集合分页查标签，转发 TagService::findByTagIds()（单值等值、数组走 IN）。
 * @param mixed $tagids   标签主键或主键数组
 * @param int   $page     页码（转发目标声明 int）
 * @param int   $pagesize 每页条数（转发目标声明 int）
 * @return array|null 以 tagid 为键（tagid 升序）、补 i 并 tag_format() 的行列表；无结果返回 NULL
 */
function tag_find_by_tagids($tagids, $page, $pagesize)
{
    // hook model_tag_tag_find_by_tagids_start.php

    // hook model_tag_tag_find_by_tagids_end.php
    return \App\Service\Content\TagService::findByTagIds($tagids, $page, $pagesize);
}
/**
 * @deprecated 兼容壳 — 全表标签计数，转发 TagService::count()（→ tag__count → 仓储 count）。
 * @return int 标签总数（转发目标声明 int）
 */
function tag_count()
{
    // hook model_tag_tag_count_start.php

    // hook model_tag_tag_count_end.php
    return \App\Service\Content\TagService::count();
}
/**
 * @deprecated 兼容壳 — 删除标签并成对失效缓存，转发 TagService::delete()（先读校验存在，再删行，
 * 清 web_tag_{tagid} 与 web_tag_{md5(name)} 两个维度键）。
 * @param mixed $tagid 标签主键
 * @return int|FALSE 影响行数；$tagid 为空、标签不存在或底层删除失败为 FALSE
 */
function tag_delete($tagid)
{
    // hook model_tag_tag_delete_start.php

    // hook model_tag_tag_delete_end.php
    return \App\Service\Content\TagService::delete($tagid);
}
/**
 * 标签行展示字段装配（引用改参，无返回值）：给 $val 补前台标签页 url。
 * url = url($conf['route']['tag'] . '-' . $val['tagid'], '', FALSE) —— 第三参 FALSE = 强制前台链接（不走后台入口）。
 * 边界：$val 为空数组直接 return（不写 url 键、不报错）；未带 tagid 键会触发 Undefined array key 告警。
 * ⚠ 装配出的 url 未经转义，前台模板 {$var} 为裸 echo（CLAUDE.md「安全红线·转义分层」），来源必须可信。
 * @param array &$val 引用传入的标签行；命中时新增 $val['url'] 键
 * @return void
 */
function tag_format(&$val)
{
    // hook model_tag_tag_format_start.php

    global $conf;
    // hook model_tag_tag_format_end.php
    if (empty($val)) return; 
    $val['url'] = url($conf['route']['tag'].'-'. $val['tagid'], '', FALSE); 
}
//--------------------------cache--------------------------
/**
 * @deprecated 兼容壳 — 按标签名读行（带两级缓存），转发 TagService::readByNameCache()。
 * 缓存：请求级 static + 分布式缓存键 web_tag_{md5(name)}（TTL 1800s）；空名直接 NULL 不占缓存，
 * 未命中不回填 ⇒ 负结果每次回表。改名后旧名键不会被 TagService::update() 清（仅 delete() 清）。
 * @param string $name 标签名（转发目标声明 string；全库未开 strict_types ⇒ 标量会被强转，数组/null 触发 TypeError）
 * @return array|null 标签行；空名或未命中为 NULL
 */
//--------------其他方法-------------
// 标签预处理 一般出入的是数组
// 修改内容标签预处理 $newtag数组, $oldtag旧的json数据
// 删除标签和绑定的主题
// 标签数据处理 $arr=新提交的数组 $tagarr=保留的旧标签
