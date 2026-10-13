<?php
/**
 * @deprecated 兼容壳 — art__* 转发到 RepositoryFactory，art_* 转发到 \App\Service\Content\ArtService
 * 新代码禁止调用，应直接使用 ArtService 或 RepositoryFactory。
 */
/** @return int|false */
/** @return int|false */
function art__update(int $aid, array $update, $d = NULL)
{
    // hook model_art_art__update_start.php

    $r = \App\Repository\RepositoryFactory::art()->update(array('aid' => $aid), $update, $d);
    // hook model_art_art__update_end.php
    return $r;
}
/**
 * @deprecated 兼容壳：转发 RepositoryFactory::art()->findOne()，新代码直接用 Repository/ArtService。
 * 取 art 主表单行原始数据（未走 format），返回形态已归一：底层无记录给 null（原 FALSE 转 null）。
 * @param array $cond    WHERE 条件（列=>值，或列=>[op=>值]）
 * @param array $orderby 排序 ['列'=>1升/-1降]
 * @param array $col     列清单，空=全列
 * @param \PdoDriver|null $d 连接/从库选择器
 * @return ?array
 */
function art__read(array $cond = array(), array $orderby = array(), array $col = array(), $d = NULL): ?array
{
    // hook model_art_art__read_start.php

    $art = \App\Repository\RepositoryFactory::art()->findOne($cond, $orderby, $col, $d);
    // hook model_art_art__read_end.php
    return $art === false ? null : $art;
}
/**
 * @deprecated 兼容壳：转发 ArtService::maxId($col, $cond, $d)。
 * 取 art 主表指定列最大值（默认 aid）；$col 须为真实列名，无匹配行返回 0。
 * @param string $col    取最大值的列
 * @param array  $cond   WHERE 条件
 * @param \PdoDriver|null $d 连接/从库选择器
 * @return int
 */
/**
 * @deprecated 兼容壳：条件固定按 aid 倒序取一行（RepositoryFactory::art()->findOne($cond, ['aid'=>-1])）。
 * @param array $cond  WHERE 条件
 * @param array $col   列清单，空=全列
 * @param \PdoDriver|null $d 连接/从库选择器
 * @return array|null 无记录返回 null（QueryBuilder::first 空结果统一给 null）
 */
// 彻底删除
/** @return int|false */
function art__delete(int $aid, $d = NULL)
{
    // hook model_art_art__delete_start.php

    $r = \App\Repository\RepositoryFactory::art()->delete(array('aid' => $aid), $d);
    // hook model_art_art__delete_end.php
    return $r;
}
/**
 * @deprecated 兼容壳：转发 RepositoryFactory::art()->find()，按条件分页取 art 主表行集。
 * 底层 find() 空结果本就返回空数组（代码里对 FALSE 的兜底是防御性归一）；与 ArtService::find 的
 * 「NULL=无结果」口径不同，调用方注意区分。$col 省略会走 SELECT * 并被记 sql_star_select（R10 留痕）。
 * @param array  $cond     WHERE 条件
 * @param array  $orderby  排序 ['列'=>1/-1]
 * @param int    $page     页码（1 起）
 * @param int    $pagesize 每页条数
 * @param string $key      结果数组以哪列值为键（''=保留数字索引）
 * @param array  $col      列清单，空=SELECT *（留痕，热点表应显式传列）
 * @param \PdoDriver|null $d 连接/从库选择器
 * @return array
 */
function art__find(array $cond = array(), array $orderby = array(), int $page = 1, int $pagesize = 20, string $key = 'aid', array $col = array(), $d = NULL): array
{
    // hook model_art_art__find_start.php

    $artlist = \App\Repository\RepositoryFactory::art()->find($cond, $orderby, $page, $pagesize, $key, $col, $d);
    // hook model_art_art__find_end.php
    return $artlist === false ? array() : $artlist;
}
/**
 * @deprecated 兼容壳：直接走 RepositoryFactory::art()->count()。
 * 分支语义：$cond 非空按给定条件计数；$cond 为空则统计「今天」——按服务器时区
 * [当日 00:00:00, +1 天) 的 create_date 区间。
 * @param array $cond WHERE 条件
 * @param \PdoDriver|null $d 连接/从库选择器
 * @return int count 签名 : int（语句失败按 0 计）
 */
function art_count_day($cond = array(), $d = NULL)
{
    // hook model_art_art_count_day_start.php

    if (!empty($cond)) {
        $n = \App\Repository\RepositoryFactory::art()->count($cond, $d);
    } else {
        $now = time();
        $start = strtotime(date('Y-m-d 00:00:00', $now));
        $end   = strtotime('+1 day', $start);
        $n = \App\Repository\RepositoryFactory::art()->count(['create_date' => ['>=' => $start, '<' => $end] ], $d);
    }
    // hook model_art_art_count_day_end.php
    return $n;
}
/**
 * @deprecated 兼容壳：转发 ArtService::count($cond, $d)——art 主表全表口径计数（非索引表 art_aid，那是不带 __ 的 art_aid_count）。
 * @param array $cond WHERE 条件
 * @param \PdoDriver|null $d 连接/从库选择器
 * @return int
 */
function art_count($cond = array(), $d = NULL)
{
    // hook model_art_art_count_start.php

    // hook model_art_art_count_end.php
    return \App\Service\Content\ArtService::count($cond, $d);
}


//--------------------------强相关--------------------------
/**
 * @deprecated 兼容壳（文件头禁令）：转发 ArtService::update($aid, $update)——
 * **只更新 art 主表数据与对应单行缓存**（键 art_<aid>），不动 tag/关联表（原注释「如更新 tag 等」即此分工），
 * 要连关联一起写请用 art_update_all / ArtService::updateAll。
 * ⚠ Art 侧的批量是假批量：底层 queryUpdateImpl 对 $aid 做 intval，传数组恒转 1、
 *   会**静默改错 aid=1 的行**（Video 侧才支持数组 IN）⇒ 批量必须逐条调用。
 * ⚠ 本壳自身不加权限判定，后台调用方要自带权限位 + CSRF 门禁（§二.6）。
 * @param int $aid 文章 ID（空值或 $update 空 ⇒ FALSE 短路，不发 SQL）
 * @param array $update SET 列（列名带 + / - 后缀 = 自增表达式）
 * @return int|false FALSE = 空参短路或底层失败；否则影响行数（0 含「无匹配行」，SQL 层错误被 db_error 吞掉计 0）
 */
function art_update($aid, $update)
{
    // hook model_art_art_update_start.php

    // hook model_art_art_update_end.php
    return \App\Service\Content\ArtService::update($aid, $update);
}
/**
 * @deprecated 兼容壳：转发 ArtService::updateAll($aid, $update)，语义与 art_update 相同的别名层
 * （缓存失效、返回口径全同 update）；名字里的 All 指「按 id 集批量」，**不是全表无条件更新**。
 * 与 art_update 的实质差别在底层：关联数据（tag 等）仍由各自的 Service 负责。
 * ⚠ 同 art_update：Art 侧 $aid 传数组会被 intval 成 1 而误改 aid=1 的行，批量请逐条调用。
 * @param int|array $aid 文章 ID（数组形式在本壳底层不安全，见上）
 * @param array $update SET 列数组
 * @return int|false FALSE = 空参短路；否则影响行数
 */
/**
 * @deprecated 兼容壳：转发 ArtService::listIndexByCid($cid, $page, $pagesize)——
 * 遍历栏目 aid 索引页，排序口径**按发布时间倒序、不包含置顶**（原注释口径；
 * 置顶/推荐位是另一条链，见 VideoStickyTrait 一类的 sticky 处理）。
 * 底层走带缓存的分类索引（缓存键 cid_page_pagesize，TTL 600s，大页码有半页翻转优化），
 * 取列仅主键 aid，所以返回的是「aid 索引行」，不是文章内容行 ⇒ 展示前还要回表（art_find/art_unified_pull）。
 * ⚠ 口径更正：底层实现（ContentQueryTrait::listIndexByCid → indexIdsByCid，见其 R11 注释）
 *   排的是**主键 aid 倒序**，不是原文注释说的「发布时间倒序」——两者只在 aid 随时间递增时才等价。
 * @param int $cid 分类 ID
 * @param int $page 页码（1 起；壳内 intval 归一）
 * @param int $pagesize 每页条数，默认 20（壳内 intval）
 * @return array|null aid 为键的索引行集；**查询为空返回 NULL**（不是空数组，调用方需 ?: 兜底）
 */
/**
 * @deprecated art_aid 表中不存在 rank 列，排序结果不确定。保留以兼容可能的调用方。
 */
/**
 * @deprecated 兼容壳（原注释「查询用户 uid 下 aid 文章数据详情」）：请求内 static 缓存
 * （键 art_find_by_uid_uid_page_pagesize）→ art_aid_find_by_uid 取索引页 → 空则返回 NULL →
 * 否则 arrlist_values 抽 aid 再 art_find 回表取格式化行。
 * ⚠ **调用即抛 RuntimeException**：底层 ContentQueryTrait::indexIdsByUid 是恒定抛错的快速失败闸
 * （生产 schema 里 art/art_aid 均无 uid 列，「内容作者」维度没有载体；裁定见该方法注释与
 * art_uid_count 的说明）。返回空数组会把「功能不存在」伪装成「该用户没有内容」，故宁可抛错。
 * 想按作者取内容：先补产品口径 → 迁移加列并回填 → 写入侧接线 → 再放开该闸并补 (uid, id) 索引。
 * 全仓当前无调用点（含插件），保留仅为兼容壳形态。
 * @param int $uid 用户 ID（同时进缓存键；注意缓存键未过 xn_safe_word，超长/特殊值只是浪费内存）
 * @param int $page 页码（1 起）
 * @param int $pagesize 每页条数，默认 20
 * @return array|null 理论上返回 aid 为键的文章行集；空索引页返回 NULL；实际路径恒抛 RuntimeException
 * @throws \RuntimeException 由 art_aid_find_by_uid → indexIdsByUid 恒抛出（该功能无 schema 支撑）
 */
/**
 * @deprecated 兼容壳：按 aid 集回表取一页文章行，转发 ArtService::find($aidarr, $pagesize, $desc)。
 * 底层显式列查询（杜绝 SELECT *）、结果**以 aid 为键**、逐行补序号字段 i 并过 format（引用式就地格式化）。
 * 原注释保留的两条口径都在用：
 *  - 「文章状态」字段语义：**0 = 通过；1~9 = 审核区（1 待审核）；10~19 = 退稿区（10 退稿）；11 = 逻辑删除**
 *    （本函数**不按状态过滤**，状态判定由调用方/后台列表负责，采集与前台展示前先自查条件）；
 *  - 不重排为入参顺序（顺序重排是 VideoQueryTrait::findByIdsOrdered 那一族的职责）。
 * ⚠ 返回 NULL 而不是空数组：无结果时 `=== NULL`，直接 foreach 前要用 `?:` 兜底。
 * @param array $aidarr aid 集合（数组值在仓储层展开成 IN 查询）
 * @param int $pagesize 取回上限，默认 20（aid 集大于它会被截断）
 * @param bool $desc TRUE = aid 降序（新→旧，默认），FALSE = 升序
 * @return array|null aid 为键的格式化行集；无结果 = NULL
 */
/**
 * @deprecated 兼容壳：转发 ArtService::findAsc($aidarr, $pagesize) = art_find 的升序包装（aid 小→大，旧→新）。
 * 原注释的两点动机保留：
 *  - 「不给 mysql 增加压力使用正序」：按主键升序回表更接近顺序扫，省掉降序排序开销；
 *    需要倒序时不必再查一遍，**用 array_reverse($artlist, TRUE) 保留键地翻**即可；
 *  - 状态字段口径同 art_find：0 通过 / 1~9 审核（1 待审核）/ 10~19 退稿（10 退稿）/ 11 逻辑删除，本层不过滤。
 * 供 unifiedPull 一类「按 id 从小到大装配列表」的链使用；返回 NULL 的坑与 art_find 相同。
 * @param array $aidarr aid 集合（数组值转 IN 查询）
 * @param int $pagesize 取回上限，默认 20
 * @return array|null aid 为键的格式化行集（升序）；无结果 = NULL
 */
/**
 * @deprecated 兼容壳：便捷封装 art_find($aidarr, 1000)，按 aid 集一次性回表取全部格式化行（上限 1000 条，aid 降序）。
 * @param array $aidarr aid 集合（数组值走 IN 查询）
 * @return array|NULL aid 为键的格式化行集；无结果返回 NULL（非空数组，调用方需 ?: 兜底）
 */
/**
 * @deprecated 兼容壳：浏览量累加，转发 ArtService::incViews($aid, $n)——本壳**无返回值**。
 * 底层是「请求级合并写」：增量先进 static 队列，首次调用注册 shutdown 钩子，
 * **响应结束后**才对累积的 id 做一次 CASE WHEN 批量 UPDATE（N+1 红线 §三.2 的口径），
 * 所以：同一请求内立刻读 views 列看不到本次增量；页面被中断/超时可能整批丢计数。
 * $aid 内部 intval、小于 0 跳过，$n 走 max(0, intval)，非法值静默不计数；不落缓存、不校验文章是否存在。
 * 原注释「大站可以单独剥离出来」= 计数写与内容读可分库/异步入队，属待评估项。
 * @param mixed $aid 文章 ID（内部 intval；空值或非数字被跳过）
 * @param int $n 本次增量，默认 1（小于 1 直接跳过）
 * @return void 无返回值（累加在请求尾异步落库，调用方拿不到影响行数）
 */
/**
 * @deprecated 兼容壳：转发 ArtService::read($aid)——进程内 static 缓存 + MultiLayerCache 键 'art_{aid}'（TTL 3600s），
 * miss 才回表并 format 后回填；update() 会主动清该键。
 * @param int|string $aid 文章 ID（空值直接得 NULL；缓存键按 intval 归一）
 * @return array|NULL 格式化后的单行；不存在=NULL
 */
function art_read($aid)
{
    // hook model_art_art_read_start.php

    // hook model_art_art_read_end.php
    return \App\Service\Content\ArtService::read($aid);
}
/**
 * @deprecated 兼容壳：转发 ArtService::delete($aid)（原注释「只删除文章和缓存」，实际链路比这句更宽）。
 * 软删口径：art__find 取回待删行 → 写回收站快照（含 art_aid 索引行）→ 物理删 art 主表 →
 * 同步清 art_aid 索引、tag_video 关联并按 tagid 做 count 批量递减（审计修复前只删主表、索引与标签留孤儿）→
 * 清 art_<aid> 单行缓存（单 id 分支还清 runtime 计数键）。
 * ⚠ **本壳不判权限**，调用方必须自带后台权限位 + CSRF 门禁（§二.6）。
 * ⚠ 批量陷阱：传数组虽然能走通链路，但内部 art__delete 的签名是 int $aid，数组会抛 TypeError
 *   ⇒ 批量删除请用 art_delete_all（真批量，数组转 IN）。
 * @param int|array $aid 文章 ID（空值直接 FALSE；数组有 TypeError 风险，见上）
 * @return int|false FALSE = 入参为空或主表删除失败；成功时为 art__delete 的 rowCount（0 = 未命中行）
 */
function art_delete($aid)
{
    // hook model_art_art_delete_start.php

    // hook model_art_art_delete_end.php
    return \App\Service\Content\ArtService::delete($aid);
}


/**
 * @deprecated 兼容壳：转发 ArtService::deleteAll($aid)——**彻底删除**口径（回收站/物理清除），
 * 原注释「删除已经发布成功的主题，单独删除传 aid，批量删除传 aids 数组」：本支是**真批量**，
 * 数组 aid 在仓储层展开成 IN 一次删完（与 art_delete 的数组入参会抛 TypeError 相反）。
 * 链路：取回待删行（取不到 ⇒ 直接 FALSE）→ 落回收站快照 → 逐 aid 清单行缓存 →
 * 批量删 art 主表与 art_aid 索引 → 清 tag_video 关联并按 tagid 递减计数；开头 set_time_limit(0)
 * 解除本请求时限（批量删慢）。与 art_delete 的差别是不回收 runtime 计数缓存键。
 * ⚠ 本壳与底层都不判权限：调用方负责后台权限位 + CSRF 门禁（§二.6）。
 * @param int|array $aid 文章 aid，或 aid 数组（批量）；空值返回 FALSE
 * @return bool TRUE = 删除链路跑完（**行不存在不算成功**）；FALSE = 入参为空或库中无对应行。
 *              返回值不反映各步 rowCount，各步失败不回滚也不报错 ⇒ 别当事务用
 */
 
/**
 * @deprecated 兼容壳：按标题关键词搜文章，转发 ArtService::findByKeyword($keyword, $d)（原注释「搜索标题」）。
 * 底层口径（性能红线 §三.2）：默认是**前导通配 LIKE 百分号 匹配，必然全表扫**；
 * 开了 conf['search_use_fulltext'] 且是 MySQL 时走 ngram FULLTEXT，未建索引/词长不足会自动回退 LIKE。
 * 命中上限固定 **60 条**、取轻量列（排除 content 等大字段）、按 aid 降序重排后逐行 format。
 * 关键词在仓储层已做 like_escape + 控制符剥离（值仍走绑定，注入面已收口），
 * 但**输出侧仍裸**：模板 `{$var}` 不转义（红线 §二.4），展示前自行按上下文处理。
 * @param string $keyword 关键词；空值直接返回 NULL（不发查询）
 * @param object|null $d 连接/从库选择器（NULL = 默认连接）
 * @return array|null aid 为键的命中行集（无命中 = 空数组）；$keyword 为空 = NULL
 */
/**
 * @deprecated 兼容壳：转发 ArtService::maxId()（无参 ⇒ 默认取主键 aid 列最大值），用于「下一篇文章 aid」一类容量探测。
 * 与 art_max_aid 的区别：本函数定死 aid 列且不带条件。
 * @return int 无行=0
 */
/**
 * @deprecated 兼容壳：文章行的展示层格式化，转发 ArtService::format(&$art)。
 * **引用入参**：格式化结果就地写回传入的 $art（补 create_date_fmt / create_date_fmt_7d 等展示字段、
 * 按 $gid/$uid/$catelist 组装权限与分类信息），同时返回值也是该行的处理结果 ⇒
 * 调用方传变量而非字面量/数组下标临时表达式，否则拿到的是「就地改过但引用断掉」的形态。
 * 原注释保留的状态口径（展示与过滤都要按它理解，本函数**不做状态过滤**、只解释字段含义）：
 * 文章状态 **0 = 通过；1~9 = 审核区（1 待审核）；10~19 = 退稿区（10 退稿）；11 = 逻辑删除**。
 * ⚠ 格式化产出的是**可直接进模板的展示串**，其中日期/分类名等由系统生成；
 * 涉及用户可控原值（标题等）时仍受「模板裸 echo」红线约束（§二.4），别把 format 当转义用。
 * @param array $art 文章行（**引用传递**，就地补字段；空行直接返回不做处理）
 * @return mixed 底层 format 的返回值（该实现主要靠引用副作用产出，调用方应读 $art 本身）
 */
/**
 * @deprecated 已下沉 ArtService::listAccessFilter，此函数仅做兼容委托
 */
/**
 * @deprecated 兼容壳：转发 ArtService::safeInfo($art)。
 * 剥掉行内敏感字段（userip、user.arts/posts/credits/golds/money），user 子行再走 user_safe_info 同口径裁剪；
 * 值传入值返回（不改原引用），供对外输出前洗敏。
 * @param array $art 文章行（可含 user 子数组）
 * @return array 裁剪后的行副本
 */
/**
 * @deprecated 已下沉 ArtService::filter，此函数仅做兼容委托
 */
//------------------------ 其他方法 ------------------------
/**
 * @deprecated 兼容壳：集合文章 aid 一次性统一拉取，转发 ArtService::unifiedPull($arr)
 * （原注释「避免多次查询 art 表」= 性能红线 §三.2 的 N+1 收敛口径：先拿索引行，再一次回表）。
 * 入参是**索引表取回的行集**组成的两个键：stickylist（置顶）与 aidlist（普通），
 * 两者按 aid 为键做数组合并，**同键时 stickylist 优先**；随后
 * `arrlist_values 抽 aid → array_unique → find()` 一次回表，再依次做
 * 访问权限过滤（listAccessFilter，按全局 $gid）与**洗敏**（safeInfo：剥 userip 等敏感字段），
 * 最后按传入顺序重排（array2_sort_key）。
 * ⚠ 返回是**包了一层的数组**，取数据要读 `['artlist']`；空输入返回 **NULL**（不是空数组），
 *   调用方按 falsy 兜底（同文件 queryPullForList 桥就是用 array_value 给空串兜底的）。
 * @param array $arr 形如 array('stickylist' => 行集, 'aidlist' => 行集)，两键均可省略
 * @return array|null 空输入 = NULL；否则 array('artlist' => 已过滤洗敏的文章行集)
 */
 

//--------------------------cache--------------------------
/**
 * @deprecated 兼容壳：转发本文件的 art_read($aid)（→ ArtService::read）。
 * 原注释两条的口径都保留：「已格式化，从缓存中读取，避免重复从数据库取数据」——
 * 读的是**格式化之后**的行，两级缓存 = 请求内 static memo ⇒ 分布式缓存（键 art_<aid>，TTL 3600s），
 * miss 才回表 format 并回填；写侧 ArtService::update()/delete() 会主动清该键，
 * 所以「改了库直接读这里」在正常链路下不会读到旧值，**绕过 Service 手工 SQL 写库才会脏**。
 * ⚠ 紧邻的英文注释「external callers exist (route/art.php)」已过期：本轮 grep 全仓（含 route/、
 *   template/、plugins/）**无任何调用点**，route/art.php 现走 art_read/Service 一侧；保留仅作历史痕迹。
 * 与 art_read 的唯一区别就是这层壳名，行为完全一致（同一份缓存、同一份返回形态）。
 * @param int|string $aid 文章 ID（空值 ⇒ NULL；缓存键按 intval 归一，'12abc' 与 12 同键）
 * @return array|null 格式化后的单行；文章不存在 = NULL（空值不缓存，故不存在的 aid 每次都回表）
 */
/**
 * @deprecated 兼容壳：转发 RepositoryFactory::artAid()->replace($arr, $d)——art_aid 索引表 REPLACE INTO（整行插入，主键冲突则先删后插）。
 * @param array $arr 列=>值 的整行数据
 * @param \PdoDriver|null $d 连接/从库选择器
 * @return int|false lastInsertId（无自增/失败=0）；空入参=false
 */
function art_aid__create($arr = array(), $d = NULL)
{
    // hook model_art_art_aid__create_start.php

    $r = \App\Repository\RepositoryFactory::artAid()->replace($arr, $d);
    // hook model_art_art_aid__create_end.php
    return $r;
}
/**
 * @deprecated 兼容壳：转发 RepositoryFactory::artAid()->update($cond, $update, $d)。
 * ⚠ QueryBuilder 无 where 的 update 直接抛异常（防全表误写），$cond 不可为空。
 * @param array $cond   WHERE 条件
 * @param array $update SET 列（列名带 + 后缀=自增表达式）
 * @param \PdoDriver|null $d 连接/从库选择器
 * @return int|false 影响行数（值未变更 MySQL 返回 0 属正常）；空参=false
 */
/**
 * @deprecated 兼容壳：转发 RepositoryFactory::artAid()->findOne()——取索引表 art_aid 单行（LIMIT 1）。
 * @param array $cond    WHERE 条件
 * @param array $orderby 排序 ['列'=>1/-1]
 * @param array $col     列清单，空=全列
 * @param \PdoDriver|null $d 连接/从库选择器
 * @return array|null 无记录=NULL
 */
/**
 * @deprecated 兼容壳：转发 RepositoryFactory::artAid()->find()——索引表分页行集，底层空结果给空数组。
 * @param array  $cond     WHERE 条件
 * @param array  $orderby  排序 ['列'=>1/-1]
 * @param int    $page     页码（1 起）
 * @param int    $pagesize 每页条数
 * @param string $key      结果以哪列值为键
 * @param array  $col      列清单，空=SELECT *（sql_star_select 留痕）
 * @param \PdoDriver|null $d 连接/从库选择器
 * @return array
 */
function art_aid__find($cond = array(), $orderby = array(), $page = 1, $pagesize = 20, $key = 'aid', $col = array(), $d = NULL)
{
    // hook model_art_art_aid__find_start.php

    $arr = \App\Repository\RepositoryFactory::artAid()->find($cond, $orderby, $page, $pagesize, $key, $col, $d);
    // hook model_art_art_aid__find_end.php
    return $arr;
}
/**
 * @deprecated 兼容壳：转发 RepositoryFactory::artAid()->delete($cond, $d)。
 * ⚠ 底层 delete() 空 $cond 短路返回 false（QueryBuilder 无 where 删除会抛异常，防全表误删）。
 * @param array $cond WHERE 条件（不可为空）
 * @param \PdoDriver|null $d 连接/从库选择器
 * @return int|false 影响行数（0=无匹配行）；空参=false
 */
/**
 * @deprecated 兼容壳：转发 RepositoryFactory::artAid()->count($cond, $d)——索引表 art_aid 计数（非 art 主表）。
 * @param array $cond WHERE 条件
 * @param \PdoDriver|null $d 连接/从库选择器
 * @return int
 */
/**
 * @deprecated 兼容壳：转发 RepositoryFactory::artAid()->bigInsert($arr, $d)——索引表多行批量写（QueryBuilder::bulkInsert 单语句），
 * 成功后会联动内容页缓存版本失效（bumpContentPageCache）。
 * @param array $arr 多行 列=>值 数据集
 * @param \PdoDriver|null $d 连接/从库选择器
 * @return int|false 影响行数；空入参=false
 */
/**
 * @deprecated 兼容壳：转发 RepositoryFactory::artAid()->bigUpdate($cond, $update, $d)——按主键单语句 CASE WHEN 批量 UPDATE。
 * ⚠ 底层口径：$cond 只取第一个键名当主键列（键值被忽略），实际更新范围是 array_keys($update) 的 IN 集合；
 * $update 形如 array(主键值 => array(列=>值))。详见 DbTableRepository::bigUpdate 注释。
 * @param array $cond   仅列名有意义（如 array('aid' => $ids)）
 * @param array $update 主键值=>列数据的映射
 * @param \PdoDriver|null $d 连接/从库选择器
 * @return int|false 影响行数；$cond/$update 为空=false
 */
//--------------------------强相关--------------------------
/**
 * @deprecated 兼容壳：往 art_aid **索引表**写一行（原注释无，语义取自底层与调用点）。
 * 空 $arr 先短路 FALSE（不发 SQL），否则转本文件的 art_aid__create($arr)
 * → RepositoryFactory::artAid()->replace() ⇒ **REPLACE INTO 语义**：主键 aid 冲突则先删后插，
 * 所以它天然是「按 aid upsert 索引行」，重复调用不留副本，但会整行覆盖（未传的列被清成默认值）。
 * 与内容主表的关系：本函数**不碰 art 表**，只维护「分类/列表页用的 aid 索引」；
 * 删内容请走 ArtService::delete/deleteAll（它们会同步清索引），别只删索引行留孤儿内容。
 * 现网唯一调用点是采集链 ApiExecutionService（写完主表后补索引行）。
 * @param array $arr 索引表整行数据，列名 => 值（须含 aid；空数组返回 FALSE）
 * @return int|false 底层 REPLACE 的 lastInsertId（索引表无自增列 ⇒ 正常为 0）；空入参或失败 = FALSE
 */
function art_aid_create($arr)
{
    // hook model_art_art_aid_create_start.php

    if (empty($arr)) return FALSE;
    $r = art_aid__create($arr);
    if (FALSE === $r) return FALSE;
    // hook model_art_art_aid_create_end.php
    return $r;
}
/**
 * @deprecated 兼容壳：读 art_aid **索引表**单行，转本文件的 art_aid__read(array('aid' => $aid))
 * → RepositoryFactory::artAid()->findOne()（LIMIT 1）。
 * 原注释「单次查询 aid，正常直接单次查询主表」的口径要保留：**取内容请走 art_read/art_find（主表）**，
 * 本函数只用于确认索引行是否存在或读索引列（如某 aid 挂在哪个 cid 下），别拿来当内容读取用。
 * 无缓存、每次都发 SQL；索引表行不含标题/正文等内容字段。
 * @param int|string $aid 文章 ID（直接进 WHERE 的 aid 等值条件，值走绑定路径）
 * @return array|null 索引表单行；记录不存在 = NULL（不是空数组，调用方需判空）
 */
/**
 * @deprecated 兼容壳：按主键改 art_aid 索引表的 **cid** 列（原注释「主键更新，若移动栏目则需要更新此表 cid」）。
 * 口径：$aid 或 $cid 为空(falsy) ⇒ 先短路返回 FALSE，**不动库**（防止无主键的宽更新）。
 * 实际写的是 art_aid 表，不动 art 主表的 cid ⇒ **改栏目必须两张表一起改**：
 * 主表走 art_update()/ArtService::update()，索引走这里，漏一边会让分类列表页（读索引）与文章页（读主表）不一致。
 * 底层是 art_aid__update(array('aid'=>$aid), array('cid'=>$cid)) → RepositoryFactory::artAid()->update()
 * （QueryBuilder 预编译路径，条件非空，不会全表写）。
 * @param int|string $aid 文章 ID（主键；空值直接 FALSE）
 * @param int|string $cid 目标分类 ID（空值直接 FALSE；注意分类 0 无法被写入）
 * @return int|false 影响行数（0 = 索引里没有该 aid 的行，常见于「只写了主表没补索引」）；空参或底层失败 = FALSE
 */
/**
 * @deprecated 兼容壳：遍历**全表**文章 aid（原注释「遍历所有文章 aid」），
 * 转发 ArtService::indexIds($page, $pagesize, $desc, 'aid', array('aid')) ——
 * 即索引表 art_aid 的按 aid 排序分页，显式只取 aid 一列（避免索引表 SELECT 星号）。
 * 无过滤条件：回收站/采集全量重算一类批处理才用它，前台列表请按分类走 art_aid_find_by_cid，
 * 别让整表分页出现在请求路径里（深分页仍是 OFFSET 形态，技术债 #12）。
 * @param int $page 页码（1 起；壳内 intval 归一）
 * @param int $pagesize 每页条数，默认 20（壳内 intval）
 * @param bool $desc TRUE = aid 降序（新→旧），FALSE = 升序（壳内 (bool) 转换，非空值都算 TRUE）
 * @return array 以 aid 为键的行集（仅含 aid 列）；无结果 = 空数组
 */
/* 遍历用户所有文章
 * @param $uid 用户ID
 * @param int $page 页数
 * @param int $pagesize 每页记录条数
 * @param bool $desc 排序方式 TRUE降序 FALSE升序
 * @param string $key 返回的数组用那一列的值作为 key
 * @param array $col 查询哪些列
 */
/**
 * @deprecated 兼容壳：遍历某栏目（或多个栏目）下的文章 aid（原注释「遍历栏目下 aid，支持数组
 * `$cid = array(1,2,3)`」），转发 ArtService::indexIdsByCid($cid, $page, $pagesize, $desc, 'aid', array('aid'))。
 * 走索引表 art_aid 的等值/IN 条件（数组 $cid 由仓储层展开成 IN），**显式只取 aid 一列**，
 * 结果是「aid 索引行」而非内容行 ⇒ 展示前要回表（art_find / art_unified_pull）。
 * $cid 为空（含 0、空串、空数组）直接返回**空数组**且不发查询；
 * 底层带请求内缓存的分页版是 art_find_aid（listIndexByCid，TTL 600s），高频列表页优先用它。
 * @param int|array $cid 分类 ID 或 ID 数组（转 IN）
 * @param int $page 页码（1 起；壳内 intval）
 * @param int $pagesize 每页条数，默认 1000（壳内 intval）
 * @param bool $desc TRUE = aid 降序（新→旧）；FALSE = 升序（壳内 (bool) 转换）
 * @return array 以 aid 为键的索引行集；无结果/空 $cid = 空数组
 */
/**
 * @deprecated 兼容壳：转发 ArtService::topByCid($cid, $page, $pagesize)（ContentRankTrait）——
 * 全周期累计榜取 aid：按 art 主表 views 列降序分页，不走流水表聚合。
 * ⚠ $desc 形参未透传（topByCid 恒降序），仅历史兼容占位。
 * @param mixed $cid     分类过滤（int/array；空值返回空数组）
 * @param int   $page    页码（1 起）
 * @param int   $pagesize 每页条数
 * @param bool  $desc    未生效，保留兼容
 * @return array 以 aid 为键的行数组（仅含主键列）；空 cid 或无数据=空数组
 */
/**
 * @deprecated 兼容壳：空 $aid 短路 FALSE，否则转 art_aid__delete(array('aid'=>$aid))——只删索引表行，不动 art 主表
 * （删内容请走 ArtService::delete/deleteAll）。
 * @param int $aid 文章 ID
 * @return int|false 底层 DELETE 影响行数；空参=false
 */
/**
 * @deprecated 兼容壳：转发 ArtService::indexCount()（无参）——索引表 art_aid 全表行数，非 art 主表口径。
 * @return int
 */
/**
 * 统计用户文章数 —— **生产 nncms_art_aid 无 uid 列，本功能无载体，调用即抛 RuntimeException**。
 * 潜伏缺陷裁定（与 art_aid_find_by_uid / indexIdsByUid 同型同因）：
 * `indexCount(array('uid' => $uid))` 会生成 `WHERE uid=?` → MySQL 1054 Unknown column。
 * 快速失败而非静默返回 0（0 会把「功能不存在」伪装成「该作者没有文章」）。
 */
function art_uid_count($uid)
{
    // hook model_art_art_uid_count_start.php

    // hook model_art_art_uid_count_end.php
    throw new \RuntimeException('art_uid_count 不可用：nncms_art_aid/nncms_art 均无 uid 列（无「内容作者」维度）');
}
