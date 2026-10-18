<?php
namespace App\Service\Content;

class CateService
{
    /**
     * 新建分类：转发 RepositoryFactory::cate()->create()（QueryBuilder 真·预编译 INSERT，非法列名被 safeColumns 剔除），
     * 随后 deleteCache() 失效分类列表/别名缓存。
     * @param array $data 分类行数据（列=>值；cid 自增主键可省略）
     * @return int|false 新行自增 cid（写失败=0）；$data 为空返回 FALSE
     */
    public static function create(array $data)
    {
        $result = \App\Repository\RepositoryFactory::cate()->create($data);
        self::deleteCache();
        return $result;
    }

    /**
     * 更新分类：转发 cate()->update(array('cid' => $cid), $data)，随后 deleteCache() 失效分类缓存。
     * $data 列名带 '+'/'-' 后缀 = 原子自增/自减表达式（调用点用 'son-' => 1 扣父类子数）。
     * ⚠ 本方法本身不再补 format/别名同步——别名改动生效依赖 deleteCache 清 'cate-alias-cache'。
     * @param int $cid 分类主键
     * @param array $data 列=>值
     * @return int|false 受影响行数（MySQL 值未变更时=0，属正常）；$data 为空返回 FALSE
     */
    public static function update(int $cid, array $data)
    {
        $result = \App\Repository\RepositoryFactory::cate()->update(array('cid' => $cid), $data);
        self::deleteCache();
        return $result;
    }

    /**
     * 读单个分类，两条口径返回形态不同：
     *  · conf['cache']['enable'] 开（默认）：只查引导阶段 cate_list_cache() 预载的全局 $catelist
     *    （行已由 findFmt 补 url 等展示字段），查不到返回 **空数组**，不触库；
     *  · 关：直查 cate 表 findOne()（未传列 ⇒ 全列，见 DbTableRepository::findOne 注释），
     *    命中后现做 format()，查不到返回 **NULL**。
     * ⚠ 站群域名下预载列表与本站可能不同源（listCache 按域名分路），跨域名调用注意口径。
     * @param int $cid 分类主键
     * @return array 分类行；缓存路径无记录=空数组，直查路径无记录=NULL
     */
    public static function read(int $cid, bool $withContent = false)
    {
        $conf = _SERVER('conf');         global $catelist;
        if (!empty($conf['cache']['enable'])) {
            if (empty($catelist[$cid])) return array();
            $row = $catelist[$cid];
            // 列表口径不含 content 大列（见 findFmt 列清单）⇒ 需要正文的调用方显式传 $withContent=TRUE，
            // 此处补一次单行读取。**不能让缺列悄悄变成空正文**：后台分类编辑页拿到空 content 后
            // 原样提交会把单页正文清空（数据丢失）；单页路由同理会渲染空页。
            // 默认 FALSE 保证 alias 解析等高频调用不再多一次查询。
            if ($withContent && !array_key_exists('content', $row)) {
                $full = \App\Repository\RepositoryFactory::cate()->findOne(array('cid' => $cid));
                if (is_array($full) && array_key_exists('content', $full)) {
                    $row['content'] = $full['content'];
                }
            }
            return $row;
        }
        $cate = \App\Repository\RepositoryFactory::cate()->findOne(array('cid' => $cid));
        $cate and self::format($cate);
        return $cate;
    }

    /**
     * 删除分类（业务级联口径，fail-closed）：
     *  · 分类必须存在于全局 $catelist（引导阶段预载），取不到即拒绝删除返回 FALSE；
     *  · type=1（频道）先按 5000/页分页取 vod_vid 索引，把该频道下全部视频走
     *    VideoService::deleteAll 级联硬删（含回收站快照/标签/置顶清理，量大耗时）；
     *  · 有父类（cup）则给父类 son- 1；
     *  · 删 cate 表行并 deleteCache()。
     * ⚠ 不校验权限，调用方（后台路由）负责权限位 + CSRF；文章面（art_aid）不在此级联范围。
     * @param int $cid 分类主键
     * @return int|false 受影响行数（cate()->delete）；入参空或分类不存在为 FALSE
     */
    public static function delete(int $cid)
    {
        global $catelist;
        if (empty($cid)) return FALSE;
        $cate = $catelist[$cid] ?? array();
        if (empty($cate)) return FALSE;
        $cate['videos'] = VideoService::countByCid($cid);
        if (1 == intval($cate['type'] ?? 0)) {
            $pagesize = 5000;
            $totalpage = $cate['videos'] > 5000 ? ceil($cate['videos'] / $pagesize) : 1;
            for ($i = 1; $i <= $totalpage; ++$i) {
                $videolist = \App\Repository\RepositoryFactory::vodVid()->find(array('cid' => $cid), array(), $i, $pagesize, 'vid', array('vid'));
                if ($videolist) {
                    $vids = array();
                    foreach ($videolist as $video) $vids[] = $video['vid'];
                    // ⚠ 不再吞返回值（复核修正）：deleteAll 在「回收站快照失败/列宽不足」时返回 FALSE 且**未删任何内容**。
                    //   旧写法忽略它，继续删分类 ⇒ 分类没了、一批视频成孤儿（cate 已删但 vod 仍在，后台无法按分类管理）。
                    //   现 fail-closed：直接中止分类删除并把结果交回调用方。
                    if (!empty($vids) && FALSE === \App\Service\Content\VideoService::deleteAll($vids)) {
                        return FALSE;
                    }
                }
            }
        }
        !empty($cate['cup']) and self::update(intval($cate['cup']), array('son-' => 1));
        $result = \App\Repository\RepositoryFactory::cate()->delete(array('cid' => $cid));
        self::deleteCache();
        return $result;
    }

    /**
     * 分类列表查询（进程级 static 记忆化：同参数组合在常驻 worker 内只查一次库）。
     * 转发 cate()->find(..., 'cid')——全列取回、按 cid 为键，未传列清单（cate 表列面小）；
     * 默认 orderby rank 降序、页宽 1000（≈全量）。
     * ⚠ 记忆化的数组是同引用返回，调用方就地修改会污染后续命中结果（如 findFmt 的 format 就是就地加字段）。
     * @param array $cond 查询条件（applyCond 语法）
     * @param array $orderby 列=>1/-1，默认 rank DESC
     * @param int $page 页码（1 起）
     * @param int $pagesize 页宽
     * @return array cid => 原始分类行；无结果空数组
     */
    public static function find(array $cond = array(), array $orderby = array('rank' => -1), int $page = 1, int $pagesize = 1000, array $col = array())
    {
        static $cache = array();
        // 列清单进静态缓存键：同条件不同列口径不能互相复用（收口批次复核：列表口径排除 content）
        $key = md5(xn_json_encode($cond) . xn_json_encode($orderby) . $page . '_' . $pagesize . '_' . xn_json_encode($col));
        if (isset($cache[$key])) return $cache[$key];
        $cache[$key] = \App\Repository\RepositoryFactory::cate()->find($cond, $orderby, $page, $pagesize, 'cid', $col);
        return $cache[$key];
    }

    /**
     * 分类计数：转发 cate()->count($cond)（QueryBuilder COUNT(*)）。
     * @param array $cond 查询条件；空数组 = 全表计数
     * @return int
     */
    public static function count(array $cond = array())
    {
        return \App\Repository\RepositoryFactory::cate()->count($cond);
    }

    /**
     * 用户组对分类的权限判定（前台阅读门禁用；fail-closed：分类不在全局 $catelist 直接 FALSE）。
     * 两档口径：
     *  · 分类开启独立权限（accesson=1）：要求「组权限位未显式置 0」且「分类 accesslist 对该 gid
     *    显式授予 $access」同时成立——分类白名单是必要条件；
     *  · 未开启：只看组权限位 $grouplist[$gid][$access]。
     * $access 为权限键名（现用调用点传 'allowread'）。依赖引导预载的 $grouplist/$catelist，不触库。
     * @param int|string $cid 分类主键
     * @param int|string $gid 用户组主键
     * @param string $access 权限键
     * @return bool
     */
    public static function accessUser($cid, $gid, string $access): bool
    {
        global $grouplist, $catelist;
        if (empty($catelist[$cid])) return FALSE;
        $group = $grouplist[$gid] ?? array();
        $cate = $catelist[$cid];
        if (!empty($cate['accesson'])) {
            return (!isset($group[$access]) || $group[$access]) && !empty($cate['accesslist'][$gid][$access]);
        }
        return !empty($group[$access]);
    }

    /**
     * cate 表 cid 最大值（≈最新分类 id）：转发 cate()->maxId('cid')。
     * @return int 无行时为 0
     */
    public static function maxId()
    {
        return \App\Repository\RepositoryFactory::cate()->maxId('cid');
    }

    //------------------------ 展示格式化（原 model/cate.php cate_find_fmt 等搬入） ------------------------
    /**
     * 分类列表（格式化版）——**列表口径刻意排除 `content` 大列**（收口批次复核修正）：
     * `content` 是单页正文 mediumtext，而本方法产物会被 `listCache()` 以 `catelist` 键缓存 7200s
     * 并在**每个前台请求**里作为 `$catelist` 全局携带；若随列表取回，站点有 N 个单页就把 N 份正文
     * 塞进每次请求的内存与分布式缓存（O(页面数 × 正文大小)）。单页正文改由 route/page.php 按 cid
     * 单行 `cate_read()` 取（管理员编辑页同样走 cate_read，本来就带全列）。
     * @param array    $cond     条件
     * @param array    $orderby  排序
     * @param int      $page     页码
     * @param int      $pagesize 每页条数
     * @param string[] $col      列清单（默认列表口径，不含 content）
     */
    public static function findFmt($cond = array(), $orderby = array('rank' => -1), $page = 1, $pagesize = 1000, $col = array())
    {
        empty($col) and $col = array(
            'cid', 'cup', 'son', 'name', 'type', 'model', 'category', 'alias', 'rank',
            'display', 'nav_display', 'create_date', 'seo_key', 'seo_des', 'seo_title', 'videos', 'arts',
        );
        $catelist = self::find($cond, $orderby, $page, $pagesize, $col);
        if ($catelist) {
            foreach ($catelist as $key => &$cate) {
                self::format($cate);
            }
        }
        return $catelist;
    }

    /**
     * 分类行展示字段就地格式化（按引用修改，原 model/cate.php 格式化面搬入）：
     * 补 create_date_fmt；type=1 的频道按 category 形态生成 url——1=专题页(cate-)、
     * 2=有内容走单页(page-{seo_des})否则列表页(list-)、3/默认=列表页；
     * url_rewrite_on>1 且有 alias 时，category 0/1 的 url 改用别名伪静态；最后恒补 type_url。
     * ⚠ url 由别名/seo_des 等入库值拼装，转义边界在入库侧（param/xn_html_safe），此处不加工不转义；
     *   空行直接返回不修改。
     * @param array $cate 分类行（须含 type/category/model 等键），按引用原地修改；本方法无返回值
     */
    public static function format(&$cate)
    {
        $conf = _SERVER('conf');
        $route_list = $conf['route']['list'];
        $route_cate = $conf['route']['cate'];
        if (empty($cate)) return;
        $cate['create_date_fmt'] = date('Y-n-j', $cate['create_date']);
        if ($cate['type']) {
            switch ($cate['model']) {
                default:
                    switch ($cate['category']) {
                        case 1:
                            $cate['url'] = url($route_cate . '-' . $cate['cid'], array(), FALSE);
                            break;
                        case 2:
                            // 单页（审查修复轮 A4 已实现 route/page.php + page.html）：恒拼 page-<seo_des>。
                            // 此前按 videos 非空才拼、为空回退列表页——单页链接与其内容数无关，回退只会
                            // 造成语义混乱；且旧实现下该链接实际落到首页（无单页路由）。
                            $cate['url'] = url('page-' . trim($cate['seo_des']), array(), FALSE);
                            break;
                        case 3:
                            $cate['url'] = url($route_list . '-' . $cate['cid'], array(), FALSE);
                            break;
                        default:
                            $cate['url'] = url($route_list . '-' . $cate['cid'], array(), FALSE);
                            break;
                    }
                    break;
            }
            if ($conf['url_rewrite_on'] > 1 && $cate['alias']) {
                if (0 == $cate['category'] || 1 == $cate['category']) {
                    $cate['url'] = url($cate['alias'], array(), FALSE);
                }
            }
        }
        $cate['type_url'] = url($conf['route']['type'] . '-' . $cate['cid'], array(), false);
    }

    // 门户 获取需要在频道显示的栏目 cid name index_new最新显示数量
    public static function channelCategory($cid)
    {
        global $catelist_show;
        static $cache = array();
        if (isset($cache[$cid])) return $cache[$cid];
        if (empty($catelist_show[$cid])) return NULL;
        $cate = $catelist_show[$cid];
        $cache[$cid] = $cate['son'] ? arrlist_cond_orderby($catelist_show, array('cup' => $cid, 'type' => 1, 'category' => 0), array('cid' => -1), 1, 1000) : NULL;
        return $cache[$cid];
    }

    /**
     * 单行 URL 生成（不改引用、不补其他字段）：category 0=列表页(list-)、1=专题页(cate-)、
     * 2=单页(page-{seo_des})。与 format() 的差异：不处理 alias 伪静态、type=0 也照出 url。
     * ⚠ $cate['category'] 不在 0/1/2 时 $url 未定义——PHP 8 触发 Warning 并返回 NULL（现存口径，未加兜底）。
     * @param array $cate 分类行（读 category/cid/seo_des）
     * @return string|NULL 路由 URL；category 非 0/1/2 时为 NULL
     */
    public static function formatUrl($cate)
    {
        $conf = _SERVER('conf');
        $route_list = $conf['route']['list'];
        $route_cate = $conf['route']['cate'];
        if (0 == $cate['category']) {
            // 列表URL
            $url = url($route_list . '-' . $cate['cid'], array(), FALSE);
        } elseif (1 == $cate['category']) {
            $url = url($route_cate . '-' . $cate['cid'], array(), FALSE);
        } elseif (2 == $cate['category']) {
            $url = url('page-' . trim($cate['seo_des']), array(), FALSE);
        }
        return $url;
    }

    /**
     * 别名映射表：遍历全局 $catelist 生成 alias => cid（伪静态别名解析用），进程级 static 记忆化。
     * 重复 alias first-wins：保留先出现的 cid，后来的记 cate_error 日志丢弃（此前 array_flip 后者静默覆盖，审计修复）。
     * ⚠ 边界：$catelist 未装载/为空时返回空字符串 ''（不是空数组，调用方需判型）。
     * ✅ 方向已修正：原实现在同进程第二次调用时会返回**相反方向**的表（首次 alias => cid、
     *   之后命中 static 返回构建中间态的 cid => alias）——因为 static 存的是中间态而返回的是 flip 结果。
     *   现 static 存最终返回值，任意次调用方向恒为 alias => cid。
     *   （历史上未暴露是因为唯一常态入口 aliasCache() 自带一层 static，使本方法每进程至多执行一次；
     *   若有调用方绕过 aliasCache 直接二次调本方法，修前会拿到反表。）
     * @return array|string alias => cid 映射（多次调用同方向）；分类未装载时为 ''
     */
    public static function aliasMap()
    {
        global $catelist;
        $key = 'cate-alias';
        static $cache = array();
        if (isset($cache[$key])) return $cache[$key];
        if (empty($catelist)) return '';
        // 中间态：cid => alias，仅为保序做下面的 first-wins 去重，不再直接当缓存用
        $byCid = array();
        foreach ($catelist as $val) {
            if ($val['alias']) $byCid[$val['cid']] = $val['alias'];
        }
        // 重复 alias 检测：first-wins + 告警（此前 array_flip 后者静默覆盖；审计修复）
        $flip = array();
        foreach ($byCid as $cid => $alias) {
            if (isset($flip[$alias])) {
                xn_log('duplicate cate alias ignored: ' . $alias . ' (cid=' . $cid . ')', 'cate_error');
                continue;
            }
            $flip[$alias] = $cid;
        }
        // ⚠ 方向翻转已修：原实现把中间态（cid => alias）写进 static 却返回 flip 后的
        //   （alias => cid），于是同进程第二次调用返回**相反方向**的表。现缓存最终返回值，
        //   两次调用方向一致（常态入口 aliasCache 自身的 static 仍会让本方法每进程至多执行一次）。
        $cache[$key] = $flip;
        return $flip;
    }

    /**
     * aliasMap() 的双层缓存版：进程 static（键 cate-alias-cache，PHP-FPM 跨请求存活，
     * 本进程至多回源一次）+ 跨进程多层缓存（同键名）。cache_get 返回 NULL（未命中）才调 aliasMap()，
     * 回源结果为空时**不写分布式缓存**（防把未装载态固化）。
     * 常态调用方：index.inc.php 别名路由解析（经 cate_alias_cache 壳）与后台别名查重。
     * ⚠ deleteCache() 只清分布式层，本进程 static 清不掉——warm worker 上旧别名映射的滞留窗口属既有取舍。
     * @return array|string alias => cid 映射；无别名分类时为 ''（非数组，调用方需判型）
     */
    public static function aliasCache()
    {
        $conf = _SERVER('conf');
        $key = 'cate-alias-cache';
        static $cache = array(); // 用静态变量只能在当前 request 生命周期缓存，跨进程需要再加一层缓存：redis/memcached/xcache/apc
        if (isset($cache[$key])) return $cache[$key];
        $arr = cache_get($key);
        if (NULL === $arr) {
            $arr = self::aliasMap();
            !empty($arr) AND cache_set($key, $arr);
        }
        $cache[$key] = empty($arr) ? '' : $arr;
        return $cache[$key];
    }

    /**
     * 全站分类列表装载（index.inc.php 引导期 `$catelist = cate_list_cache()` 的实现）：
     * 三层取数 = 进程 static（键 cate-list，**PHP-FPM 跨请求存活**，口径同类头注释）→
     * 跨进程缓存（仅非站群域名启用，键 catelist，TTL 7200s）→ findFmt() 现查（rank 降序全列格式化）。
     * 站群分支：不读写共享缓存（键无域名维度，多站共用会互相污染）；全局 $catelist 已被上游装载
     * 就直接沿用，否则现查、且**不回填**缓存。
     * ⚠ 进程 static 无域名/失效维度：warm worker 上首个计算结果长期服务后续请求，deleteCache()
     *   只清共享层两键、清不掉它——改分类后已预热 worker 的旧列表窗口属既有取舍，强一致走后台清缓存。
     * @return array|null cid => 格式化分类行（findFmt 产物）；装载无果为 NULL
     */
    public static function listCache()
    {
        global $catelist, $domain;
        $key = 'cate-list';
        static $cache = array();
        if (isset($cache[$key])) return $cache[$key];
        if (empty($domain['site']) || !is_array($domain['site'])) {
            $catelist = cache_get('catelist');
        }
        if (NULL === $catelist) {
            $catelist = self::findFmt();
            if (empty($domain['site']) || !is_array($domain['site'])) {
                cache_set('catelist', $catelist, 7200);
            }
        }
        $cache[$key] = is_array($catelist) ? $catelist : NULL;
        return $cache[$key];
    }

    /**
     * 失效分类列表与别名映射的跨进程缓存（键 catelist / cate-alias-cache）。
     * ⚠ 幂等闸 static $deleted 是**进程级**（PHP-FPM 同 worker 跨请求存活，口径见类头注释
     * 「与原函数级 static 等价」）：本 worker 首次调用后，后续请求的 create/update/delete 触发的
     * 再调用都不再真正删——共享层两键的清退只此一次；且本方法不动 listCache()/aliasCache()/find()
     * 各自的进程 static，warm worker 上仍存在读到本进程旧列表的窗口。属既有取舍，全站强一致
     * 需走后台清缓存。
     */
    public static function deleteCache(): void
    {
        static $deleted = FALSE;
        if ($deleted) return;
        cache_delete('cate-alias-cache');
        cache_delete('catelist');
        \App\Service\Front\TypeService::bumpFacets();
        $deleted = TRUE;
    }

    /**
     * indexVideo() 的双层缓存壳：进程 static + 跨进程缓存（键 index_video_{num}，TTL 600s，仅非空回源结果写缓存）。
     * 注意缓存键只含页宽 $num 不含域名/分类维度——站群多站共用同一键空间，与 listCache 的站群跳过策略不同，
     * 属首页区块既有口径。
     * @param array $catelist 全量分类列表（仅回源时消费；缓存命中可为任意值）
     * @param int $num 每分类取条数
     * @return array|null 区块数据；空结果为 NULL——NULL 只在本进程 static 记忆，不写分布式缓存
     *         （下一进程仍回源重算）
     */
    /**
     * 首页视频区块装配（不读缓存，缓存壳是 indexVideoCache）：
     *  ① category_list_show($catelist, 1) 取首页展示的频道；
     *  ② 各频道最新 $num 条 vid 经 cateTopRows('vod_vid', dir=-1) 单条 UNION ALL 批量取回
     *     （P-2；异常自动降级逐分类查询）；
     *  ③ 叠加首页置顶位（VideoService::stickyIndexMap），合并 vid 集一次 VideoService::findAsc
     *     (light=TRUE，排除 play_url 大字段) 装配内容行，array_reverse 成新→旧顺序；
     *  ④ 按 vid 归属回填 list[cid]['news']，置顶行进 sticky 并编 i 序号；list 按 rank 降序、cid 重排键。
     * @param array $catelist 全量分类列表（listCache/findFmt 产物，需含展示频道）
     * @param int $num 每频道取最新条数（同时进缓存键 index_video_{num}）
     * @return array|null 空入参 NULL；否则 ['list' => cid=>区块(含 news 可选), 'sticky' => vid=>行(可选)]；
     *         区块内无命中视频时该 cid 下没有 news 键（模板按 isset 兜底）
     */
    /**
     * channelVideo() 的双层缓存壳：进程 static + 分布式缓存（键 channel_video_{cid}_{pagesize}，
     * TTL 1200s，仅非空结果回写；空结果返回 NULL 且不固化）。
     * @param int|string $cid 频道分类 id
     * @param int|string $pagesize 每子频道取条数（进缓存键）
     * @return array|null 频道区块；无数据为 NULL
     */
    public static function channelVideoCache($cid, $pagesize)
    {
        $key = 'channel_video_' . $cid . '_' . $pagesize;
        static $cache = array();
        if (isset($cache[$key])) return $cache[$key];
        $arr = cache_get($key);
        if (NULL === $arr) {
            $arr = self::channelVideo($cid, $pagesize);
            empty($arr) || cache_set($key, $arr, 1200);
        }
        $cache[$key] = empty($arr) ? NULL : $arr;
        return $cache[$key];
    }

    /**
     * channelArt() 的双层缓存壳：进程 static + 分布式缓存（键 channel_art_{cid}_{pagesize}，
     * TTL 1200s，仅非空结果回写；空结果返回 NULL 且不固化）。
     * @param int|string $cid 频道分类 id
     * @param int|string $pagesize 每子频道取条数（进缓存键）
     * @return array|null 频道区块；无数据为 NULL
     */
    public static function channelArtCache($cid, $pagesize)
    {
        $key = 'channel_art_' . $cid . '_' . $pagesize;
        static $cache = array();
        if (isset($cache[$key])) return $cache[$key];
        $arr = cache_get($key);
        if (NULL === $arr) {
            $arr = self::channelArt($cid, $pagesize);
            empty($arr) || cache_set($key, $arr, 1200);
        }
        $cache[$key] = empty($arr) ? NULL : $arr;
        return $cache[$key];
    }

    /**
     * 频道页文章区块装配（不读缓存，缓存壳是 channelArtCache）：
     *  ① channelCategory($cid) 取该频道下展示中的子频道；
     *  ② 各子频道经 cateTopRows('art_aid', dir=1) 单条 UNION ALL 批量取 aid（P-2；异常自动降级逐条）；
     *  ③ ArtService::findAsc 一次装配内容行后 array_reverse（aid 大者在前），回填 list[cid]['news']，
     *     list 按 rank 降序、cid 重排键。
     * ⚠ 本方法不产生 sticky 键（其后的 sticky 编号分支是恒不可达的死代码，从 channelVideo 拷贝残留，仅记录不动）；
     *   取页方向传 dir=1（ASC，即各子频道主键最小的一页，非「最新 pagesize 条」；降级路径 find 同方向，两路一致）。
     * @param int|string $cid 频道分类 id；空 ⇒ NULL
     * @param int $pagesize 每子频道条数
     * @return array|null 空 cid ⇒ NULL；否则 ['list' => cid=>子频道区块（news 子键可选）]
     */
    public static function channelArt($cid, $pagesize)
    {
        if (empty($cid)) return NULL;
        $page = 1;
        $category_catelist = self::channelCategory($cid);
        $arrlist = array();
        $cate_aids = array();
        $aidlist = array();
        if ($category_catelist) {
            foreach ($category_catelist as &$_cate) {
                $arrlist['list'][$_cate['cid']] = array(
                    'cid' => $_cate['cid'],
                    'name' => $_cate['name'],
                    'rank' => $_cate['rank'],
                    'type' => $_cate['type'],
                    'url' => $_cate['url'],
                    'channel_new' => $pagesize,
                );
                $cat_top_cids[] = intval($_cate['cid']); // P-2：挪到循环外批量取
            }
            $aidlist += $cate_aids;
        }
        // P-2：单条 UNION ALL 取各分类 TOP-N
        if (!empty($cat_top_cids)) {
            foreach (self::cateTopRows('art_aid', 'aid', $cat_top_cids, $pagesize, 1) as $key => $_art) {
                $cate_aids[$key] = $_art;
            }
            $aidlist += $cate_aids;
        }
        $aidarr = arrlist_values($aidlist, 'aid');
        if (empty($aidarr)) {
            $arrlist['list'] = isset($arrlist['list']) ? array_multisort_key($arrlist['list'], 'rank', FALSE, 'cid') : array();
            return $arrlist;
        }
        $aidarr = array_unique($aidarr);
        $items = ArtService::findAsc($aidarr, count($aidarr));
        $items = array_reverse((array)$items);
        foreach ($items as &$_art) {
            isset($cate_aids[$_art['aid']]) and $arrlist['list'][$_art['cid']]['news'][$_art['aid']] = $_art;
        }
        if (isset($arrlist['sticky'])) {
            $i = 0;
            foreach ($arrlist['sticky'] as &$val) {
                ++$i;
                $val['i'] = $i;
            }
        }
        isset($arrlist['list']) and $arrlist['list'] = array_multisort_key($arrlist['list'], 'rank', FALSE, 'cid');
        return $arrlist;
    }

    /**
     * 频道页视频区块装配（不读缓存，缓存壳是 channelVideoCache）：
     *  ① channelCategory($cid) 取该频道下展示中的子频道；
     *  ② 各子频道经 cateTopRows('vod_vid', dir=1) 单条 UNION ALL 批量取 vid（P-2；异常自动降级逐条），
     *     叠加该频道置顶位 VideoService::stickyListMap($cid)；
     *  ③ VideoService::findAsc(light=TRUE，排除 play_url 大字段) 一次装配后 array_reverse
     *     （集合内按 vid 降序），回填 list[cid]['news'] 与 sticky（sticky 编 i 序号），
     *     list 按 rank 降序、cid 重排键。
     * ⚠ 取页方向传 dir=1（ASC，即各子频道 vid 最小的一页，非「最新 pagesize 条」）；
     *   与 indexVideo 的 dir=-1 相反，降级路径 find 同方向、两路一致。
     * @param int|string $cid 频道分类 id；空 ⇒ NULL
     * @param int $pagesize 每子频道条数
     * @return array|null 空 cid ⇒ NULL；否则 ['list' => cid=>子频道区块（news 子键可选）, 'sticky' => vid=>行(可选)]
     */
    public static function channelVideo($cid, $pagesize)
    {
        if (empty($cid)) return NULL;
        $page = 1;
        $category_catelist = self::channelCategory($cid);
        $arrlist = array();
        $cate_vids = array();
        $vidlist = array();
        if ($category_catelist) {
            foreach ($category_catelist as &$_cate) {
                $arrlist['list'][$_cate['cid']] = array(
                    'cid' => $_cate['cid'],
                    'name' => $_cate['name'],
                    'rank' => $_cate['rank'],
                    'type' => $_cate['type'],
                    'url' => $_cate['url'],
                    'channel_new' => $pagesize,
                );
                $cat_top_cids2[] = intval($_cate['cid']); // P-2：挪到循环外批量取
            }
            $vidlist += $cate_vids;
        }
        // P-2：单条 UNION ALL 取各分类 TOP-N
        if (!empty($cat_top_cids2)) {
            foreach (self::cateTopRows('vod_vid', 'vid', $cat_top_cids2, $pagesize, 1) as $key => $_video) {
                $cate_vids[$key] = $_video;
            }
            $vidlist += $cate_vids;
        }
        $stickylist = VideoService::stickyListMap($cid);
        empty($stickylist) || $vidlist += $stickylist;
        $vidarr = arrlist_values($vidlist, 'vid');
        if (empty($vidarr)) {
            $arrlist['list'] = isset($arrlist['list']) ? array_multisort_key($arrlist['list'], 'rank', FALSE, 'cid') : array();
            return $arrlist;
        }
        $vidarr = array_unique($vidarr);
        $items = VideoService::findAsc($vidarr, count($vidarr), true);
        $items = array_reverse((array)$items);
        foreach ($items as &$_video) {
            isset($cate_vids[$_video['vid']]) and $arrlist['list'][$_video['cid']]['news'][$_video['vid']] = $_video;
            isset($stickylist[$_video['vid']]) and $arrlist['sticky'][$_video['vid']] = $_video;
        }
        if (isset($arrlist['sticky'])) {
            $i = 0;
            foreach ($arrlist['sticky'] as &$val) {
                ++$i;
                $val['i'] = $i;
            }
        }
        isset($arrlist['list']) and $arrlist['list'] = array_multisort_key($arrlist['list'], 'rank', FALSE, 'cid');
        return $arrlist;
    }

    // ============================================================
    // 以下方法自 core/model/cate.php 下沉（混合模式收敛）
    // 进程级缓存语义与原函数级 static $cache 等价（PHP-FPM 跨请求）
    // ============================================================

    /** @var array|null 进程级缓存（原 category_tree 函数 static 语义） */
    private static $categoryTreeCache = null;
    /** @var array|null 进程级缓存（原 all_channel 函数 static 语义） */
    private static $allChannelCache = null;
    /** @var array|null 进程级缓存（原 all_category 函数 static 语义） */
    private static $allCategoryCache = null;
    /** @var array 进程级缓存（原 category_list 函数 static 语义，按参数键区分） */
    private static $categoryListCache = array();
    /** @var array 进程级缓存（原 category_list_show 函数 static 语义，按参数键区分） */
    private static $categoryListShowCache = array();
    /** @var array|null 进程级缓存（原 cate_list 函数 static 语义） */
    private static $cateListCache = null;
    /** @var array|null 进程级缓存（原 nav_list 函数 static 语义） */
    private static $navListCache = null;

    /**
     * 获取CMS全部栏目，包括频道的二叉树结构
     */
    public static function categoryTree($catelist)
    {
        if (empty($catelist)) return NULL;
        if (null !== self::$categoryTreeCache) return self::$categoryTreeCache;
        $arrlist = arrlist_cond_orderby($catelist, array('type' => 1), array(), 1, 1000);
        $arrlist = self::categoryTreeFormat($arrlist);
        self::$categoryTreeCache = array_multisort_key($arrlist, 'rank', FALSE, 'cid');
        return self::$categoryTreeCache;
    }

    /**
     * 格式化为树状结构 (会舍弃不合格的结构)
     */
    public static function categoryTreeFormat($catelist)
    {
        foreach ($catelist as &$v) {
            if ($v['cup']) {
                $catelist[$v['cup']]['sonlist'][$v['cid']] = $v;
                unset($catelist[$v['cid']]);
            }
        }
        return $catelist;
    }

    /**
     * 返回网站所有频道
     */
    /**
     * 取「版块级」栏目列表（一二级：type=1 且 category<2）。
     * ⚠ 名实修正：原注释写「返回二叉树结构」，但实现只是 arrlist_cond_orderby 条件过滤，
     *    返回**扁平一维列表**（不组树、不挂 children）——需要树结构请走 categoryTree()/allChannel() 一侧。
     * 进程级缓存：与自 core/model/cate.php 下沉前的函数级 static 语义等价（同请求内只算一次），
     *    同请求内改过 $catelist 再调不会反映改动。
     * @param array $catelist 全量栏目（cate_list_cache 结果）
     * @return array|null 空入参返回 NULL；否则为过滤后的列表（键不保证连续）
     */
    /**
     * 按 model/display/category 过滤栏目列表
     */
    public static function categoryList($catelist, $model = 0, $display = 0, $category = 0)
    {
        if (empty($catelist)) return NULL;
        $key = $model . '-' . $display . '-' . $category;
        if (isset(self::$categoryListCache[$key])) return self::$categoryListCache[$key];
        if ($display) {
            foreach ($catelist as $k => $val) {
                if (1 == $val['display'] && 1 == $val['type'] && $val['category'] == $category) {
                    self::$categoryListCache[$key][$k] = $val;
                }
            }
        } else {
            foreach ($catelist as $k => $val) {
                if (1 == $val['type'] && $val['category'] == $category) {
                    self::$categoryListCache[$key][$k] = $val;
                }
            }
        }
        return empty(self::$categoryListCache[$key]) ? NULL : self::$categoryListCache[$key];
    }

    /**
     * 按 nav_display/display/category 过滤栏目列表
     */
    /**
     * 版块列表（type=0）
     */
    public static function cateList($catelist)
    {
        if (empty($catelist)) return array();
        if (null !== self::$cateListCache) return self::$cateListCache;
        self::$cateListCache = array();
        foreach ($catelist as $_cid => $_cate) {
            if ($_cate['type']) continue;
            self::$cateListCache[$_cid] = $_cate;
        }
        return self::$cateListCache;
    }

    /**
     * 导航栏目列表（剔除 nav_display=0）
     */
    /**
     * 栏目安全信息（原 cate_safe_info：原样返回）
     */
    public static function safeInfo($cate)
    {
        return $cate;
    }

    /**
     * 过滤栏目 SEO 冗余字段
     */
    public static function filter($catelist)
    {
        foreach ($catelist as &$val) {
            unset($val['seo_des'], $val['seo_title'], $val['seo_key'], $val['create_date_fmt']);
        }
        return $catelist;
    }

    /**
     * 按 model 查找栏目（原 findcate：返回 name/url 两键数组）
     */
    public static function findCate($data, $model, $defaultUrl = '/', $defaultName = '默认')
    {
        if (!is_array($data) || empty($data)) {
            return array('name' => $defaultName, 'url' => $defaultUrl);
        }
        foreach ($data as $v) {
            if (!is_array($v)) continue;
            if (!isset($v['model'], $v['url'], $v['name'])) continue;
            if ($v['model'] == $model && !empty($v['url'])) {
                return array('name' => $v['name'], 'url' => $v['url']);
            }
        }
        return array('name' => $defaultName, 'url' => $defaultUrl);
    }


    /**
     * P-2（2026-10-10 性能修复）：多分类 TOP-N 批量取——单条 UNION ALL 替代逐分类 SELECT。
     *
     * 背景：首页/栏目页按「每个分类取最新 N 条」装配（原实现每分类一条 find()，
     * 分类数 N ⇒ N 次 DB 往返）。本方法把 N 条查询合并为 1 条 UNION ALL：
     *   每个子查询带各自 ORDER BY + LIMIT（语义与原 find 完全一致），cid 全部走 ? 绑定。
     *   LIMIT 数值与表名/主键列名为调用方字面量（intval / 白名单构造），无注入面。
     * 任何方言/驱动异常（SQLite/PG 对 UNION 子查询细节差异、表缺失等）→ 记日志并
     * 自动降级回逐分类查询（行为等价原实现）。
     *
     * @param string $table 索引表名（'vod_vid'/'art_aid'，调用方常量）
     * @param string $pk    主键列（'vid'/'aid'，调用方常量）
     * @param array  $cids  分类 id 列表
     * @param int    $num   每分类取多少条（intval）
     * @param int    $dir   排序方向（1=ASC 其余=DESC，与 applyOrderBy 语义一致）
     * @return array 主键 => 行（行仅含主键列，与原 find(col=[pk]) 返回形状一致）
     */
    private static function cateTopRows(string $table, string $pk, array $cids, int $num, int $dir): array
    {
        if (!$cids) return array();
        $db = _SERVER('db');
        if (!$db || !method_exists($db, 'prepare_execute')) return array();
        $num = max(1, $num);
        $order = 1 == $dir ? 'ASC' : 'DESC';
        $parts = array();
        $params = array();
        foreach ($cids as $cid) {
            $parts[] = "(SELECT `$pk`, `cid` FROM `" . $db->tablepre . $table . "` WHERE `cid` = ? ORDER BY `$pk` $order LIMIT $num)";
            $params[] = intval($cid);
        }
        $rows = array();
        try {
            $st = $db->prepare_execute(implode(' UNION ALL ', $parts), $params);
            if ($st) {
                foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                    if (isset($r[$pk])) $rows[intval($r[$pk])] = array($pk => intval($r[$pk]));
                }
                return $rows;
            }
        } catch (\Throwable $e) {
            if (function_exists('xn_log')) {
                xn_log('cateTopRows union failed (' . $table . '): ' . $e->getMessage() . ' → fallback to per-cate queries', 'cate_union_error');
            }
        }
        // 降级路径：逐分类查询（与原实现等价）
        $repo = \App\Repository\RepositoryFactory::table($table, $pk);
        foreach ($cids as $cid) {
            $batch = $repo->find(array('cid' => $cid), array($pk => $dir), 1, $num, $pk, array($pk));
            foreach ((array)$batch as $key => $row) $rows[$key] = $row;
        }
        return $rows;
    }
}
