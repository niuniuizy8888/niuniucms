<?php
// @deprecated 兼容壳 — 底层函数转发到 RepositoryFactory，业务函数转发到 VideoService。新代码禁止调用。

/**
 * @deprecated 兼容壳（文件头禁令：底层函数转发 RepositoryFactory、业务函数转发 VideoService，新代码禁止调用）：
 * 取**热搜表 search_hot** 的行集，转发 RepositoryFactory::hotKeyword()->find(...)。
 * 局部变量名沿用 $videolist 是复制粘贴遗留，这里返回的不是视频行，别被误导。
 * 走 Repository 预编译查询路径：条件数组值转 IN、标识符与比较符经双白名单校验（§二.3）；
 * $key 非空时结果以该列值为外层键（键不连续，不要按下标 0 取值）。
 * 热搜相关的新代码请用同文件的 hot_search()（带缓存）或 KeywordService，不要经本壳。
 * ⚠ 搜索词是用户可控入库内容，而模板 `{$var}` 编译成裸 echo（红线 §二.4）⇒ 展示前必须按上下文转义。
 * @param array $cond WHERE 条件（search_hot 列名 => 值或条件数组）
 * @param array $orderby 排序，形如 array('列' => 1 升序 / -1 降序)
 * @param int $page 页码（1 起）
 * @param int $pagesize 每页条数，默认 20
 * @param string $key 以哪一列的值作结果外层键（留空 = 连续下标）
 * @param array $col 列清单（空 = 全列，会触发 sql_star_select 日志，见 §三.2）
 * @param object|null $d 连接/从库选择器（NULL = 默认连接）
 * @return array 行集（$key 非空时为「列值 => 行」映射）；无结果为空数组
 */
/**
 * 取热搜词条目列表 —— 转发 KeywordService::hotItems()（读 search_hot 表，带缓存）。
 * @param int $pagesize 条目数（内部 intval）
 * @return array 每项含 rank/n/keyword/count/url
 */
function hot_search($pagesize = 20)
{
    // hook model_vod_hot_search_start.php

   // hook model_vod_hot_search_end.php
   return \App\Service\Content\KeywordService::hotItems(_SERVER('conf'), intval($pagesize));
}
/**
 * 热搜词行格式化 —— 转发 KeywordService::formatHotRows()。
 * @param array $r search_hot 原始行数组
 * @return array 格式化条目列表；$r 空或非数组返回空数组
 */
/**
 * 新增/替换置顶视频记录 —— 转发 RepositoryFactory::stickyVod()->replace()。
 * 副作用：清 sticky_video_list 缓存并推进 sticky_video_ver 版本令牌。
 * @param array $arr 记录数据
 * @param object|null $d 连接选择器（NULL=默认连接）
 * @return int|FALSE 仓储 replace 结果；$arr 为空返回 FALSE
 */
/**
 * 按 vid 更新置顶视频记录 —— 转发 RepositoryFactory::stickyVod()->update()。
 * @param int $vid 主键
 * @param array $arr 更新字段
 * @param object|null $d 连接选择器（NULL=默认连接）
 * @return int|FALSE 影响行数；$arr 为空返回 FALSE
 */
/**
 * 查置顶视频列表 —— 转发 RepositoryFactory::stickyVod()->find()。
 * @param array $cond 条件数组
 * @param array $orderby 排序（列=>1/-1）
 * @param int $page 页码（自 1 起）
 * @param int $pagesize 每页条数
 * @param string $key 结果按键列重排，默认 'vid'
 * @param array $col 显式列清单（空=SELECT * 并留痕）
 * @param object|null $d 连接选择器
 * @return array 行数组（按 $key 重排键），无结果返回空数组
 */
/**
 * 按 vid 删除置顶视频记录 —— 转发 RepositoryFactory::stickyVod()->delete()。
 * @param int $vid 主键
 * @param object|null $d 连接选择器
 * @return int|FALSE 影响行数
 */
/**
 * 统计置顶视频数 —— 转发 RepositoryFactory::stickyVod()->count()。
 * @param array $cond 条件数组
 * @param object|null $d 连接选择器
 * @return int 记录数
 */
/**
 * 新增视频 —— 转发 RepositoryFactory::vod()->insert()。
 * @param array $arr 字段数据
 * @param object|null $d 连接选择器
 * @return int|FALSE 新自增 ID（写失败可为 0）；$arr 为空返回 FALSE
 */
/**
 * 按 vid 更新视频 —— 转发 RepositoryFactory::vod()->update()。
 * @param int $vid 主键
 * @param array $update 更新字段
 * @param object|null $d 连接选择器
 * @return int|FALSE 影响行数；$update 为空返回 FALSE
 */
function video__update($vid, $update, $d = NULL)
{
    // hook model_vod_video__update_start.php

    $r = \App\Repository\RepositoryFactory::vod()->update(array('vid' => $vid), $update, $d);
    // hook model_vod_video__update_end.php
    return $r;
}
/**
 * 按条件读单条视频 —— 转发 RepositoryFactory::vod()->findOne()（原始行，未做 format 加工）。
 * @param array $cond 条件数组
 * @param array $orderby 排序（决定多条命中时取哪条）
 * @param array $col 显式列清单（空=SELECT *）
 * @param object|null $d 连接选择器
 * @return array|NULL 单行数据；无命中返回 NULL
 */
function video__read($cond = array(), $orderby = array(), $col = array(), $d = NULL)
{
    // hook model_vod_video__read_start.php

    $video = \App\Repository\RepositoryFactory::vod()->findOne($cond, $orderby, $col, $d);
    // hook model_vod_video__read_end.php
    return $video;
}
/**
 * 取视频表指定列最大值 —— 转发 RepositoryFactory::vod()->maxId()。
 * @param string $col 列名（须匹配标识符正则，否则抛 InvalidArgumentException）
 * @param array $cond 条件数组
 * @param object|null $d 连接选择器
 * @return int 最大值；无行返回 0
 */
/**
 * 取 vid 最大（最后插入）的一条视频 —— 转发 vod()->findOne()，orderby 固定 vid 倒序。
 * @param array $cond 条件数组
 * @param array $col 显式列清单（空=SELECT *）
 * @param object|null $d 连接选择器
 * @return array|NULL 单行原始数据；无命中返回 NULL
 */
/**
 * 按 vid 删除视频 —— 转发 RepositoryFactory::vod()->delete()（仅删主表，不清关联缓存/置顶）。
 * @param int $vid 主键
 * @param object|null $d 连接选择器
 * @return int|FALSE 影响行数
 */
function video__delete($vid, $d = NULL)
{
    // hook model_vod_video__delete_start.php

    $r = \App\Repository\RepositoryFactory::vod()->delete(array('vid' => $vid), $d);
    // hook model_vod_video__delete_end.php
    return $r;
}
/**
 * 查视频列表 —— 转发 RepositoryFactory::vod()->find()（原始行，未做 format 加工）。
 * @param array $cond 条件数组
 * @param array $orderby 排序（列=>1/-1）
 * @param int $page 页码（自 1 起）
 * @param int $pagesize 每页条数
 * @param string $key 结果按键列重排，默认 'vid'
 * @param array $col 显式列清单（空=SELECT *，vod 表 40 列会读取放大）
 * @param object|null $d 连接选择器
 * @return array 行数组（按 $key 重排键），无结果返回空数组
 */
function video__find($cond = array(), $orderby = array(), $page = 1, $pagesize = 20, $key = 'vid', $col = array(), $d = NULL)
{
    // hook model_vod_video__find_start.php

    $videolist = \App\Repository\RepositoryFactory::vod()->find($cond, $orderby, $page, $pagesize, $key, $col, $d);
    // hook model_vod_video__find_end.php
    return $videolist;
}
/**
 * 统计今日（服务器本地 0 点起的自然日区间 [start, start+1d)）新增视频数。
 * 转发 RepositoryFactory::vod()->count()，固定按 create_date 范围条件。
 * @return int 记录数
 */
function video_count_day()
{
    // hook model_vod_video_count_day_start.php

    $now = time();
    $start = strtotime(date('Y-m-d 00:00:00', $now));
    $end   = strtotime('+1 day', $start);
    $n= \App\Repository\RepositoryFactory::vod()->count(['create_date' => ['>=' => $start, '<' => $end] ]);
    // hook model_vod_video_count_day_end.php
    return $n;
}
/**
 * 统计视频数 —— 转发 RepositoryFactory::vod()->count()。
 * @param array $cond 条件数组
 * @param object|null $d 连接选择器
 * @return int 记录数
 */
function video_count($cond = array(), $d = NULL)
{
    // hook model_vod_video_count_start.php

    $n = \App\Repository\RepositoryFactory::vod()->count($cond, $d);
    // hook model_vod_video_count_end.php
    return $n;
}
/**
 * 写索引表记录 —— 转发 RepositoryFactory::vodVid()->replace()（vod_vid 表仅 vid/cid 两列）。
 * @param array $arr 记录数据
 * @param object|null $d 连接选择器
 * @return int|FALSE 仓储 replace 结果；$arr 为空返回 FALSE
 */
function video_vid__create($arr = array(), $d = NULL)
{
    // hook model_vod_video_vid__create_start.php

    $r = \App\Repository\RepositoryFactory::vodVid()->replace($arr, $d);
    // hook model_vod_video_vid__create_end.php
    return $r;
}
/**
 * 按条件更新索引表 —— 转发 RepositoryFactory::vodVid()->update()。
 * @param array $cond 条件（空返回 FALSE，防全表写）
 * @param array $update 更新字段
 * @param object|null $d 连接选择器
 * @return int|FALSE 影响行数；$cond 或 $update 为空返回 FALSE
 */
/**
 * 按条件读索引表单行 —— 转发 RepositoryFactory::vodVid()->findOne()。
 * @param array $cond 条件数组
 * @param array $orderby 排序
 * @param array $col 显式列清单（空=SELECT *）
 * @param object|null $d 连接选择器
 * @return array|NULL 单行（vid/cid）；无命中返回 NULL
 */
/**
 * 查索引表列表 —— 转发 RepositoryFactory::vodVid()->find()。
 * @param array $cond 条件数组
 * @param array $orderby 排序（列=>1/-1）
 * @param int $page 页码（自 1 起）
 * @param int $pagesize 每页条数
 * @param string $key 结果按键列重排，默认 'vid'
 * @param array $col 显式列清单（空=SELECT *）
 * @param object|null $d 连接选择器
 * @return array 行数组（按 $key 重排键），无结果返回空数组
 */
function video_vid__find($cond = array(), $orderby = array(), $page = 1, $pagesize = 20, $key = 'vid', $col = array(), $d = NULL)
{
    // hook model_vod_video_vid__find_start.php

    $arr = \App\Repository\RepositoryFactory::vodVid()->find($cond, $orderby, $page, $pagesize, $key, $col, $d);
    // hook model_vod_video_vid__find_end.php
    return $arr;
}
/**
 * 按条件删索引表记录 —— 转发 RepositoryFactory::vodVid()->delete()。
 * @param array $cond 条件（空返回 FALSE，防全表删）
 * @param object|null $d 连接选择器
 * @return int|FALSE 影响行数
 */
/**
 * 统计索引表记录数 —— 转发 RepositoryFactory::vodVid()->count()。
 * @param array $cond 条件数组
 * @param object|null $d 连接选择器
 * @return int 记录数
 */
/**
 * 写 vod_vid 索引表（业务封装）——$arr 为空短路返回 FALSE，否则转 video_vid__create()。
 * @param array $arr 含 vid/cid 的记录数据
 * @return int|FALSE 仓储 replace 结果
 */
function video_vid_create($arr)
{
    // hook model_vod_video_vid_create_start.php

    if (empty($arr)) return FALSE;
    $r = video_vid__create($arr);
    if (FALSE === $r) return FALSE;
    // hook model_vod_video_vid_create_end.php
    return $r;
}
/**
 * 按 vid 读 vod_vid 索引行 —— 转 video_vid__read(array('vid'=>$vid))。
 * @param int $vid 视频 ID
 * @return array|NULL 索引行（vid/cid）；无记录返回 NULL
 */
/**
 * 改 vid 的所属分类 —— 转 video_vid__update()，$vid 或 $cid 为空返回 FALSE。
 * @param int $vid 视频 ID
 * @param int $cid 新分类 ID
 * @return int|FALSE 影响行数
 */
/**
 * 分页取索引表 vid 列表 —— 转发 VideoService::indexIds()。
 * @param int $page 页码（intval）
 * @param int $pagesize 每页条数（intval）
 * @param bool $desc TRUE=按 vid 倒序
 * @return array 索引行数组（以 vid 为键），无结果返回空数组
 */
/**
 * 按作者 uid 取视频 vid —— **生产 schema 无 uid 列，调用即抛 RuntimeException**。
 * 潜伏缺陷裁定：详见 ContentQueryTrait::indexIdsByUid 注释（内容表/索引表
 * 逐列实测均无 uid，原版 install.sql 自出厂即无该维度，全仓无写入方）。
 * 保留函数名以不破坏插件 hook 点；未来上线内容归属时按该注释的顺序放开。
 */
/**
 * 按分类分页取索引表 —— 转发 VideoService::indexIdsByCid()。
 * @param int $cid 分类 ID（空值不查库，直接返回空数组）
 * @param int $page 页码（intval）
 * @param int $pagesize 每页条数（intval）
 * @param bool $desc TRUE=按 vid 倒序
 * @return array 行数组（仅 vid 列，以 vid 为键）；无结果返回空数组
 */
function video_vid_find_by_cid($cid, $page = 1, $pagesize = 1000, $desc = TRUE)
{
    // hook model_vod_video_vid_find_by_cid_start.php

    // hook model_vod_video_vid_find_by_cid_end.php
    return \App\Service\Content\VideoService::indexIdsByCid($cid, intval($page), intval($pagesize), (bool)$desc, 'vid', array('vid'));
}
/**
 * 按分类取热门 vid（views 倒序）—— 转发 VideoService::topByCid()。
 * @param int|string|array $cid 分类 ID（空返回空数组）
 * @param int $page 页码（原样透传，未 intval）
 * @param int $pagesize 每页条数（原样透传）
 * @param bool $desc 已接收但未转发（topByCid 无该形参，实际排序恒为 views 倒序）
 * @return array 行数组（仅 vid 列，以 vid 为键，按 views 倒序）
 */
/**
 * 按 vid 删索引表记录 —— $vid 为空短路返回 FALSE，否则转 video_vid__delete()。
 * @param int $vid 视频 ID
 * @return int|FALSE 影响行数
 */
/**
 * 索引表总记录数 —— 转发 VideoService::indexCount()。
 * @return int 记录数
 */
/**
 * 按分类统计视频数 —— 转发 VideoService::countByCid()。
 * @param int|string|array $cid 分类 ID
 * @return int 记录数
 */
/**
 * 更新视频并失效详情缓存 —— 转发 VideoService::update()（$vid/$update 为空返回 FALSE；
 * 成功后按 vid 删 vod_<id> 缓存；$vid 支持传数组批量清缓存）。
 * @param int|array $vid 视频 ID（或 ID 数组）
 * @param array $update 更新字段
 * @return int|FALSE 影响行数
 */
function video_update($vid, $update)
{
    // hook model_vod_video_update_start.php

    // hook model_vod_video_update_end.php
    return \App\Service\Content\VideoService::update($vid, $update);
}
/**
 * @deprecated 兼容壳（文件头禁令）：转发 VideoService::updateAll($vid, $update)，
 * 原注释「更新全部数据」= 与 video_update() 同一条实现（updateAll 内部即 update 的别名），
 * 差别只在名字暗示「按 id 集批量」而**不是全表无条件更新**。
 * Video 侧的批量是真批量：$vid 传数组时经 applyCond 展开成 IN，逐 id 失效 vod_<id> 行缓存
 * （这点与 Art 侧不同——art_update_all 传数组会被 intval 成 1 而误改 aid=1，别把两边的经验互抄）。
 * 更新列走同一套口径：列名带 + / - 后缀 = 自增表达式，值走绑定；本层不做权限判定（§二.6 由调用方负责）。
 * @param int|array $vid 视频 vid，或 vid 数组（批量）
 * @param array $update SET 列数组；空数组直接 FALSE（不发 SQL）
 * @return int|false 影响行数（0 含「无匹配行」）；空参或底层失败 = FALSE
 */
/**
 * @deprecated 兼容壳：转发 VideoService::listIndexByCid()——按分类取一页内容 id（不含正文）。
 * ⚠ 更正排序口径：原行注释写「按照发布时间倒序」，实际是**按主键 vid 倒序**
 *   （ContentQueryTrait::indexIds 以 id 列做 ORDER BY，vod_vid 索引表根本没有日期列）。
 *   vid 递增顺序与入库时间大致同向，但批量补录/迁移后会不一致——要真·发布时间排序请走
 *   VideoService 侧带 create_date 的查询，别指望本方法。
 * 置顶帖不在本页结果里（置顶由 sticky 系列方法单独取）。
 * @param int $cid      分类 id
 * @param int $page     页码（内部 intval，无下限钳制——0/负数由 Repository 侧归一）
 * @param int $pagesize 每页条数
 * @return array|NULL 以 vid 为键的行集（DbTableRepository::find 第 5 参 $key 非空 ⇒ 键=该列值），
 *               每行只含 vid 一列；**空命中返回 NULL，不是空数组**（indexPageByCid 的既定契约，
 *               调用方需 `?: array()` 兜底，直接 foreach 会吃 NULL）。
 *               又因键=vid，**不要按 $rows[0] 取值**——键不连续。
 */
/**
 * @deprecated 兼容壳：转发 VideoService::descIndexByCid()——按分类取一页内容 id（倒序）。
 * ⚠ 更正两处旧口径（原行注释「按照 rank 倒序…查询主题数据详情」已废）：
 *   ① 排序：索引表 vod_vid 只有 (vid, cid)，**没有 rank 列**——历史上按 rank 排序会直接
 *      SQLSTATE[42S22] 致命报错（R11 已改为按主键 vid 倒序，确定性排序，走 (cid,vid) 索引无 filesort）。
 *      「rank 倒序」的语义（人工权重）在这里从来就没有实现过，需要权重排序请走带 rank 的正式列表查询。
 *   ② 返回的是**内容 id 行**，不是主题数据详情（要正文得再用这些 id 回查 vod 表）。
 *   置顶内容不会被本方法排除（它只按 cid 取索引行），也不保证出现在首页。
 * 全仓当前零调用点（与 art_find_desc() 同型），保留仅为兼容。
 * @param int $cid      分类 id
 * @param int $page     页码（内部 intval）
 * @param int $pagesize 每页条数
 * @return array|NULL 以 vid 为键的行集（每行含 vid、cid 两列）；空命中返回 **NULL**
 */
/**
 * @deprecated 兼容壳：按 vid 集回表取一页主题数据，转发 VideoService::find($vidarr, $pagesize, $desc, $light)
 * （原注释「vidarr 查询主题数据」）。
 * 底层显式列查询（审计 R10/P0-2：杜绝 SELECT 星号），结果**以 vid 为键**、逐行补序号字段 i 并 format。
 * ⚠ $light 是 Art 侧没有的第四参：TRUE 走轻量列清单（排除 play_from/play_url/content 等大字段，
 *   vod.play_url 实测均值 5.4KB），列表/block 热路径务必传 TRUE，只有详情页才需要完整播放地址。
 * ⚠ **不保证按入参顺序返回**（顺序重排是 VideoQueryTrait::findByIdsOrdered 的职责）；
 *   空结果返回 **NULL 而不是空数组**，直接 foreach 前要 `?: array()` 兜底。
 * @param array $vidarr vid 集合（数组值在仓储层展开成 IN）
 * @param int $pagesize 取回上限，默认 20（集合大于它会被截断）
 * @param bool $desc TRUE = vid 降序（新→旧，默认）
 * @param bool $light TRUE = 轻量列（不含播放地址等大字段）
 * @return array|null vid 为键的格式化行集；无结果 = NULL
 */
/**
 * @deprecated 兼容壳：转发 VideoService::findAsc($vidarr, $pagesize, $light) = video_find 的**升序**包装
 * （旧→新，供 unifiedPull 一类按 id 递增装配的链使用）。
 * 原注释的口径保留：「不给 mysql 增加压力使用正序」= 按主键升序回表更接近顺序扫，省掉降序排序开销；
 * **需要倒序别再查一遍**，用 `array_reverse($videolist, TRUE)`（第二参 TRUE 保住 vid 键）即可。
 * ⚠ 与 video_find 同：结果以 vid 为键、不重排为入参顺序、空结果返回 NULL；
 *   $light=TRUE 时不含 play_url 等大字段（列表热路径请用轻量形态）。
 * @param array $vidarr vid 集合（数组值转 IN 查询）
 * @param int $pagesize 取回上限，默认 20
 * @param bool $light TRUE = 轻量列清单（排除播放地址等大字段）
 * @return array|null vid 为键的格式化行集（升序）；无结果 = NULL
 */
/**
 * 播放数自增 —— 转发 VideoService::incViews()。不即时落库：请求内静态累加，
 * 注册在 register_shutdown_function 里响应结束后合并为一条 bulkUpdate 写 vod.views。
 * @param int $vid 视频 ID
 * @param int $n 增量（取 max(0, intval)）
 * @return void
 */
/**
 * 读单条视频（格式化后）—— 转发 VideoService::read()，进程内 static + 分布式缓存（TTL 见 queryReadTtl）。
 * @param int $vid 视频 ID
 * @return array|NULL 格式化后的视频行；$vid 为空或查无返回 NULL
 */
function video_read($vid)
{
    // hook model_vod_video_read_start.php

    // hook model_vod_video_read_end.php
    return \App\Service\Content\VideoService::read($vid);
}
/**
 * @deprecated 兼容壳：转发 VideoService::delete($vid)（原注释「只删除 vid 和缓存」，实际链路比这句宽）。
 * 软删口径：video__find 取回整行 → snapshotVod() 写回收站快照 → video__delete 删 vod 主表 →
 * 成功后同步清 **vod_vid 索引行、tag_video 关联与 tag.count 递减、置顶行与 sticky 缓存**
 * （审计修复前只删主表，留下孤儿索引/标签），并失效受影响行的 vod_<id> 行缓存。
 * 单 id 分支额外失效 runtime 计数缓存（数组分支不失效——首页总数须另触发生成）。
 * ⚠ 与 Art 侧不同：Video 的数组 $vid 是真批量（走 IN，不会像 art_delete 那样抛 TypeError）。
 * ⚠ 本壳与底层都**不做权限/CSRF 判定**，调用方（后台路由）必须自带（§二.6）。
 * @param int|array $vid vid 或 vid 数组；空值直接 FALSE
 * @return int|false video__delete 的影响行数（0 = 无匹配行）；入参为空或底层返回 FALSE 时为 FALSE
 */
/**
 * @deprecated 兼容壳：转发 VideoService::deleteAll($vid)（原注释「单独删除传 vid，批量删除传 vids 数组」，
 * 数组入参在这里是**真批量**，仓储层展开成 IN）。
 * 「彻底删除」口径：set_time_limit(0) 解除本请求时限（批量删耗时）→ video__find 取整行
 * （**取不到行直接返回 FALSE**，行不存在不等于删除成功）→ tag_video 关联删除并按引用次数递减 tag.count
 * → 涉置顶时清 sticky_video_list、推进 sticky_video_ver、删 vod_sticky 行 → snapshotVod() 落回收站快照
 * （含 vod_vid 关联行，供恢复重建）→ 逐 vid 失效 vod_<id> 行缓存 → 删 vod 主表与 vod_vid 索引表。
 * 与 video_delete 的差别：不失效 runtime 计数缓存，且走仓库批量删而非 video__delete。
 * ⚠ 本壳与底层都不做权限/CSRF 判定（§二.6）；返回布尔，**不反映各步 rowCount、各步失败不回滚**，别当事务用。
 * @param int|array $vid vid 或 vid 数组；空值返回 FALSE
 * @return bool TRUE = 删除链路执行完毕；FALSE = 入参为空或库中无对应行
 */
/**
 * @deprecated 兼容壳：按标题关键词搜主题，转发 VideoService::findByKeyword($keyword, $d)（原注释「搜索标题」）。
 * 性能与口径（§三.2）：默认是**前导通配 LIKE 匹配，必然全表扫**；开 conf['search_use_fulltext'] 且为 MySQL
 * 时走 ngram FULLTEXT（未建索引/MariaDB/词长不足自动回退 LIKE）⇒ 启用前先按文档建索引。
 * 上限固定 60 条、走轻量列（不含 play_url 等大字段）、命中后按 vid 降序重排并逐行 format。
 * 关键词在仓储层已 like_escape + 剥控制符、值走绑定（注入面已收口），
 * 但**输出不转义**：模板 `{$var}` 是裸 echo（红线 §二.4），展示前自行按上下文 xn_h()。
 * @param string $keyword 关键词；空值直接返回 NULL 且不发查询
 * @param object|null $d 连接/从库选择器（NULL = 默认连接）
 * @return array|null vid 为键的命中行集（无命中 = 空数组）；$keyword 为空 = NULL
 */
/**
 * @deprecated 兼容壳：按条件分页筛主题并逐行装配展示字段，转发 VideoService::findByType($cond, $page)
 * （原注释只有「筛选」两字，具体口径见底层）。
 * 排序恒为 **ORDER BY vid DESC**（审计 C-4 修复：原先无排序的 LIMIT 分页行集随执行计划漂移，
 * 相邻页会重复/漏行），PHP 侧再按 vid 倒序稳定化后逐行 format。
 * ⚠ 壳签名比底层少一个参数：底层第三参 $pagesize 为 NULL 时回落 conf['pagesize']（默认 20），
 *   本壳不透传 ⇒ **每页条数固定走配置默认值**，需要自定义页大小请用 Service 侧同名方法。
 * 条件走 RepositoryFactory::vod()->find 的预编译路径（标识符/比较符双白名单，§二.3）；
 * 结果是**完整列**（含 play_url 等大字段）且已 format，前台列表若只需摘要请另走轻量查询。
 * @param array $cond WHERE 条件（vod 列名 => 值或条件数组，如 array('cid'=>array(1,2))）
 * @param int $page 页码（1 起；壳内 intval，非数字串归 0 后由 Repository 侧归一）
 * @return array 以 vid 为键、格式化后的主题行集；无结果为空数组
 */
/**
 * 取视频表最大 vid —— 转发 VideoService::maxId()。
 * @return int 最大值；无行返回 0
 */
/**
 * @deprecated 兼容壳：主题行的展示层格式化，转发 VideoService::format(&$video, $light)。
 * **引用入参**：就地补展示字段（create_date_fmt / create_date_fmt_ymd、分类名与分类链接、
 * 详情 url（开伪静态且有 alias 时用 alias 形态）、pic 换成本地缩略图就绪的 URL、
 * tag_fmt/tag_url 由 JSON 串解出等），所以调用方读 $video 本身，不依赖返回值。
 * 原注释的状态口径保留（本函数**不做状态过滤**，只解释字段含义）：
 * 主题状态 **0 = 通过；1~9 = 审核区（1 待审核）；10~19 = 退稿区（10 退稿）；11 = 逻辑删除**。
 * ⚠ $light = TRUE 时**不装配 play_data**（列表/block 热路径专用，省掉播放地址大字段）；
 *   详情页必须传 FALSE，否则拿不到线路数据。
 * ⚠ 空行短路：$video 为空直接 return（不报错也不填字段）。
 * ⚠ 格式化产出仍是「可进模板的串」，不是安全转义：模板 `{$var}` 裸 echo（红线 §二.4），
 *   用户可控原值（标题等）要按上下文另转。
 * @param array $video 主题行（**引用传递**，就地补字段；空值不处理）
 * @param bool $light TRUE = 轻量格式化（不装配 play_data），默认 FALSE 全量
 * @return mixed 底层 format 的返回值（该实现以引用副作用为主，调用方应读 $video）
 */
/**
 * @deprecated vod 表中不存在 last_date 列。保留以兼容可能的调用方。
 */
/**
 * @deprecated 已下沉 VideoService::listAccessFilter，此函数仅做兼容委托
 */
function video_list_access_filter(&$videolist, $gid)
{
    // hook model_vod_video_list_access_filter_start.php

    // hook model_vod_video_list_access_filter_end.php
    return \App\Service\Content\VideoService::listAccessFilter($videolist, $gid);
}
/**
 * @deprecated 已下沉 VideoService::filter，此函数仅做兼容委托
 */
//------------------------ 其他方法 ------------------------
/**
 * @deprecated 兼容壳：集合主题 vid 统一拉取，转发 VideoService::unifiedPull($arr)
 * （原注释「避免多次查询 video 表」= N+1 收敛口径，§三.2：先拿索引行再一次回表）。
 * 入参是索引行集组成的三个键：stickylist（置顶）、vidlist（普通）、light（透传给 find 的轻量开关）。
 * 两列行集按 vid 为键做数组合并，**同键时 stickylist 优先**；随后抽 vid → 去重 → 一次 find 回表 →
 * video_list_access_filter 按全局 $gid 做访问权限过滤 → 按传入顺序重排（array2_sort_key）。
 * ⚠ light 的修复记录（审计）：listByCid 传下来的 light 此前被丢弃，导致列表/block 热路径
 *   虽标称轻量却仍装配 play_data；现按入参生效 ⇒ 想让列表省大字段，记得在 $arr 里带 light。
 * ⚠ 返回**包了一层**：取数据要读 `['videolist']`；空输入返回 **NULL**（不是空数组），调用方按 falsy 兜底。
 * @param array $arr 形如 array('stickylist' => 行集, 'vidlist' => 行集, 'light' => bool)，各键均可省略
 * @return array|null 空输入 = NULL；否则 array('videolist' => 已过滤重排的主题行集)
 */
/** @deprecated video_read() 已内置分布式缓存，此函数仅做兼容委托 */
/**
 * 分页取置顶缓存（带版本令牌）—— 转发 VideoService::stickyFindCacheRand()，
 * 缓存键含 sticky_video_ver，置顶变更自动失效；空结果归一为 NULL。
 * @param int|string $cid 分类过滤（空=全部）
 * @param int $page 页码（intval）
 * @param int $pagesize 每页条数（intval）
 * @return array|NULL 置顶行数组（以 vid 为键）；无数据返回 NULL（消费方需判 NULL）
 */
/**
 * 变更视频置顶状态 —— 转发 VideoService::stickyChange()：更新 vod.sticky 并 replace 置顶表行。
 * @param int $vid 视频 ID
 * @param int $sticky 置顶级别
 * @param array $video 视频行数据（取 cid/vid 用；签名要求数组，空数组直接返回 FALSE）
 * @return int|FALSE 置顶表 replace 结果
 */
/**
 * 按 vid 改置顶表分类 —— 转发 VideoService::stickyUpdateByVid()（含清置顶列表缓存、推进版本令牌）。
 * @param int $vid 视频 ID
 * @param int $newcid 新分类 ID
 * @return int|FALSE 影响行数
 */
/**
 * 删除置顶设置 —— 转发 VideoService::stickyDelete()：视频不存在返回 FALSE；
 * sticky==3 时同步回减 config.index_stickys 并 setting_set 落库；清置顶缓存。
 * @param int $vid 视频 ID
 * @return int|FALSE 置顶表 delete 影响行数
 */
/**
 * 置顶表总数 —— 转发 VideoService::stickyCount()。
 * @return int 记录数
 */
/**
 * 按置顶级别统计置顶数 —— 转发 VideoService::stickyCount(array('sticky'=>$sticky))。
 * @param int $sticky 置顶级别
 * @return int 记录数
 */
/**
 * 按分类统计置顶数 —— 转发 VideoService::stickyCount(array('cid'=>$cid))。
 * @param int $cid 分类 ID
 * @return int 记录数
 */
/**
 * 首页置顶映射（sticky==1）—— 转发 VideoService::stickyIndexMap()，按 create_date 倒序。
 * @return array|NULL 以 vid 为键的置顶行；置顶缓存为空返回 NULL
 */
function sticky_index_video()
{
    // hook model_vod_sticky_index_video_start.php

    // hook model_vod_sticky_index_video_end.php
    return \App\Service\Content\VideoService::stickyIndexMap();
}
