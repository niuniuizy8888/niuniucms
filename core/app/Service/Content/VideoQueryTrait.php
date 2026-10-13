<?php
namespace App\Service\Content;

/**
 * VideoQueryTrait — VideoService 方法簇拆分（职责文件化，调用面不变）
 * 第三刀：查询族 17 方法（indexCount/indexIds 系列/find 族/read 等）
 * 收敛至 App\Service\Content\Traits\ContentQueryTrait，本文件保留视频特有方法
 * （sitemapRows/searchKeywordIds/findByType/findByIdsOrdered/format/unifiedPull/filter）
 * 与 sitemapRows 三个装配 helper（buildVodUrl/buildTagUrls/buildPlayUrls，
 * 修复此前 0 定义致站点地图详情页 fatal）。
 */
trait VideoQueryTrait
{
    /**
     * 海报本地缩略图（R7-P09）延迟加载：Trait 被单测直载（未注册 PSR-4）时安全降级为原图。
     * 本地无缩略图 / 服务不可加载时返回原 URL，渲染期绝不阻塞或出错。
     */
    private static function localizePic(string $pic): string
    {
        if ($pic === '' || 0 !== stripos($pic, 'http')) return $pic;
        $cls = 'App\\Service\\System\\ThumbnailService';
        if (!class_exists($cls) && defined('APP_PATH')) {
            $f = APP_PATH . 'core/app/Service/System/ThumbnailService.php';
            if (is_file($f)) include_once $f;
        }
        return class_exists($cls, false) ? \App\Service\System\ThumbnailService::url($pic) : $pic;
    }

    /**
     * 站点地图行装配：从索引表取当页 vid（indexIds 传 desc=FALSE，按主键升序），再回 vod 主表按显式列补
     * vid/cid（可选 tag、play_from/play_url），逐行拼 url（依赖全局 $catelist 的 alias 做伪静态），
     * withTag/withPlay 时顺带装配 tag_url、play_urls。两处空集都短路，不发回表查询。
     * @param int  $page     页码（签名已声明）
     * @param int  $pagesize 每页条数（签名已声明）
     * @param bool $withTag  是否附带标签链接
     * @param bool $withPlay 是否附带播放地址列表
     * @return array 以 vid 为键的行列表（签名声明 array）；空页/回表无结果为空数组
     */
    public static function sitemapRows(int $page = 1, int $pagesize = 20, bool $withTag = FALSE, bool $withPlay = FALSE): array
    {
        $arrlist = self::indexIds($page, $pagesize, FALSE);
        $vidarr = arrlist_values($arrlist, 'vid');
        if (empty($vidarr)) return array();

        $col = array('vid', 'cid');
        if ($withTag) {
            $col[] = 'tag';
        }
        if ($withPlay) {
            $col[] = 'play_from';
            $col[] = 'play_url';
        }

        $videolist = \App\Repository\RepositoryFactory::vod()->find(array('vid' => $vidarr), array('vid' => 1), 1, count($vidarr), 'vid', $col);
        if (empty($videolist)) return array();

        global $catelist;
        foreach ($videolist as &$video) {
            $cate = array_value($catelist, $video['cid']);
            $video['url'] = self::buildVodUrl($video['vid'], $cate ?: array());
            if ($withTag) {
                $video['tag_url'] = self::buildTagUrls(array_value($video, 'tag'));
            }
            if ($withPlay) {
                $video['play_urls'] = self::buildPlayUrls(
                    array_value($video, 'play_from'),
                    array_value($video, 'play_url'),
                    array_value($cate, 'alias'),
                    intval($video['vid'])
                );
            }
        }
        unset($video);

        return $videolist;
    }

    // ---- sitemapRows 装配 helper（与 format()/playData 同源搬出；修复） ----
    /**
     * 生成一条视频的详情页 URL（sitemap/列表装配的共用 helper，与 format()、playData 同源搬出，
     * 修的就是「三处各写一份取址规则、改伪静态时漏改」的问题）。
     * 两条分支：
     *  - 常规：`url(conf['route']['vod'] . '-' . vid)`，即 `vod-<vid>` 形态的路由地址；
     *  - **伪静态开启（conf['url_rewrite_on'] 大于 1）且该分类有 alias** 时改用
     *    `url(urlencode(alias) . '-' . vid)` —— 别名先 urlencode，含中文/空格也不会拼出畸形路径。
     * 返回的是**站内相对路由地址**（url() 的既定形态，是否带站点路径由 conf 决定），
     * 不含协议与主机名；要绝对地址请外面拼 url_domain()。
     * ⚠ 别名分支会让「同一 vid 在不同分类下」得到不同 URL（分类 alias 参与取址），
     *   做 canonical/结构化数据时注意只认一个口径。
     * @param int|string $vid 视频 ID（参与路由串拼接）
     * @param array $cate 分类节点（只读 alias 键；无 alias 走常规分支）
     * @return string 详情页 URL（不含主机名）
     */
    private static function buildVodUrl($vid, $cate)
    {
        $conf = _SERVER('conf');
        $route_page_vod = $conf['route']['vod'];
        $url = url($route_page_vod . '-' . $vid, '', FALSE);
        if ($conf['url_rewrite_on'] > 1) {
            !empty($cate['alias']) and $url = url(urlencode($cate['alias']) . '-' . $vid, '', FALSE);
        }
        return $url;
    }

    /**
     * 把 vod.tag 列（JSON 字符串，键=tagid 值=tag 名）拼成标签链接列表。
     * @param mixed $tag vod 行的 tag 列原文（字符串 JSON；空/解码失败按空集合处理）
     * @return array array(array('name'=>标签名,'url'=>标签页 URL), ...)；无标签为空数组
     */
    private static function buildTagUrls($tag)
    {
        $conf = _SERVER('conf');
        $tag_fmt = $tag ? xn_json_decode($tag) : array();
        if (!is_array($tag_fmt)) $tag_fmt = array();
        $route_tag = $conf['route']['tag'] ?? '';
        $tagarr = array();
        foreach ($tag_fmt as $_tag => $_tagname) {
            $tagarr[] = array(
                'name' => $_tagname,
                'url' => url($route_tag . '-' . $_tag),
            );
        }
        return $tagarr;
    }

    /**
     * 解析播放地址并摊平：VideoService::playData() 装配多源播放结构后，只取其 url 字段列
     * （站点地图等只关心 URL 的场景，省掉整套源结构）。
     * @param mixed $play_from 播放源串（$$$ 分隔；转发目标签名 string，调用处已 (string) 强转）
     * @param mixed $play_url  播放地址串（组内 # 分隔多地址、$ 分隔名称与地址）
     * @param mixed $alias     栏目别名（伪静态路由前缀）
     * @param mixed $vid       视频 ID（调用处已 intval）
     * @return array 非空播放 URL 的顺序列表
     */
    private static function buildPlayUrls($play_from, $play_url, $alias, $vid)
    {
        $list = \App\Service\Content\VideoService::playData((string)$play_from, (string)$play_url, (string)$alias, intval($vid));
        $urls = array();
        foreach ($list as $item) {
            if (!empty($item['url'])) {
                $urls[] = $item['url'];
            }
        }
        return $urls;
    }

    /**
     * 按关键词搜 vid ID 列表：委托 ContentQueryRepository::searchIdsByKeyword（P-4：ngram FULLTEXT 优先，
     * 未开启/未建索引/短词自动回退前导通配 LIKE）。结果逐段 intval 去重后重排下标。
     * @param string      $keyword 关键词（转发目标签名 string；空串直接空数组）
     * @param int         $limit   返回上限（签名已声明，默认 60）
     * @param object|null $d       连接/从库选择器，NULL=默认
     * @return array vid 整数列表（签名声明 array）；无结果/空关键词为空数组
     */
    public static function searchKeywordIds($keyword, int $limit = 60, $d = NULL): array
    {
        if (empty($keyword)) return array();
        $ids = \App\Repository\RepositoryFactory::contentQuery()->searchIdsByKeyword('vod', 'vid', $keyword, $limit, $d);
        if (empty($ids)) return array();
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * 按条件分页查视频并逐行 format() 装配展示字段：ORDER BY vid DESC（C-4 修复——无排序的 LIMIT
     * 分页行集随执行计划漂移，相邻页会重复/漏行），PHP 侧再按 vid 倒序稳定化。
     * @param array    $cond     查询条件（签名已声明）
     * @param int      $page     页码（签名已声明）
     * @param int|null $pagesize 每页条数；NULL/0 回落 conf['pagesize']（默认 20）
     * @return array 以 vid 为键、format() 后的视频列表；无结果为空数组
     */
    public static function findByType(array $cond = array(), int $page = 1, ?int $pagesize = NULL)
    {
        $conf = _SERVER('conf');
        $pagesize = $pagesize ?: intval($conf['pagesize'] ?? 20);
        // 发布前检查修复（C-4）：补 ORDER BY vid DESC——无排序的 LIMIT 分页行集由执行计划决定，
        // 统计/索引漂移后相邻两页可重复或漏行（与下方 arrlist_multisort 语义一致）
        $videolist = \App\Repository\RepositoryFactory::vod()->find($cond, array('vid' => -1), $page, $pagesize, 'vid');
        if ($videolist) {
            $videolist = arrlist_multisort($videolist, 'vid', FALSE);
            foreach ($videolist as &$video) {
                self::format($video);
            }
            unset($video);
        }
        return $videolist;
    }

    /**
     * 按给定 vid 顺序批量取视频：intval 去零去重后经 video__find 一次回表（vid 为键），
     * 再严格按入参 $vidarr 顺序重排输出（库里不存在的 id 跳过），逐行补 i 序号并 format()。
     * @param array $vidarr 视频 ID 列表（可含非 int，内部 intval 归一；0 被滤除）
     * @param bool  $light  true=轻量 format（不装配 play_data，列表/block 热路径用）
     * @return array 顺序列表（0..n 重排键）；空输入/回表无结果为空数组
     */
    public static function findByIdsOrdered(array $vidarr, bool $light = FALSE): array
    {
        $vidarr = array_values(array_unique(array_filter(array_map('intval', $vidarr))));
        if (empty($vidarr)) return array();

        $videolist = video__find(array('vid' => $vidarr), array('vid' => 1), 1, count($vidarr), 'vid');
        if (!$videolist) return array();

        $items = array();
        $i = 0;
        foreach ($vidarr as $_vid) {
            if (!isset($videolist[$_vid])) continue;
            $video = $videolist[$_vid];
            ++$i;
            $video['i'] = $i;
            self::format($video, $light);
            $items[] = $video;
        }
        return $items;
    }

    //------------------------ 展示格式化（原 model/vod.php video_format 等搬入） ------------------------
    // 主题状态 0:通过 1~9 审核:1待审核 10~19:10退稿 11逻辑删除
    /**
     * 视频行的展示层格式化（原 model/vod.php 的 video_format 搬入本 Trait）。
     * 状态口径按紧邻上方注释保留：**0 = 通过；1~9 = 审核区（1 待审核）；10~19 = 退稿区（10 退稿）；
     * 11 = 逻辑删除** —— 本函数**只装配展示字段，不做状态过滤**，能不能露出由调用方的条件决定。
     * 就地写入引用参数 $video 的字段：
     *  create_date_fmt（humandate 相对时间）与 create_date_fmt_ymd、cate_name / cate_url
     *  （取自 global $catelist，缺分类时为空串）、url（同 buildVodUrl 口径：伪静态 + 分类 alias 时走别名形态）、
     *  pic 换成本地缩略图就绪的 URL（R7-P09，**渲染期不出网**，本地无缓存则回落远程原址，形状不变）、
     *  tag_fmt / tag_url（vod.tag 列 JSON 解出的标签集合与对应标签页链接列表，解不出归一成空数组）。
     * ⚠ $light = TRUE 时**不装配 play_data / play_data_json**（列表与 block 热路径专用，
     *   play_url 实测均值 5.4KB，全量装配是主要内存/带宽开销来源）；播放器页面必须走 FALSE 全量形态。
     * ⚠ 空行短路：$video 为空直接 return（既不改也不报错）。
     * @param array $video 视频行（**引用传递**，就地补展示字段）
     * @param bool $light TRUE = 轻量（不装配播放数据），默认 FALSE 全量
     * @return array|NULL 传入的行（已就地补字段）；$video 为空时该实现直接返回 NULL
     */
    public static function format(&$video, $light = FALSE)
    {
        $conf = _SERVER('conf');         global $uid, $catelist;
        $conf = _SERVER('conf');
        if (empty($video)) return;
        $video['create_date_fmt'] = humandate($video['create_date']);
        $video['create_date_fmt_ymd'] = date('Y-m-d', $video['create_date']);
        $cate = array_value($catelist, $video['cid']);
        $video['cate_name'] = array_value($cate, 'name');
        $video['cate_url'] = array_value($cate, 'url');
        $route_page_vod = $conf['route']['vod'];
        $video['url'] = url($route_page_vod . '-' . $video['vid'], '', FALSE);
        if ($conf['url_rewrite_on'] > 1) {
            !empty($cate['alias']) and $video['url'] = url(urlencode($cate['alias']) . '-' . $video['vid'], '', FALSE);
        }
        // R7-P09：海报本地缩略图就绪后原位替换 pic（本地无缩略图时返回原远程 URL，形状不变、
        // 不新增字段以免破坏按行结构断言的回归用例；渲染期不出网）。
        $video['pic'] = self::localizePic((string)($video['pic'] ?? ''));
        $video['tag_fmt'] = $video['tag'] ? xn_json_decode($video['tag']) : array();
        if (!is_array($video['tag_fmt'])) $video['tag_fmt'] = array();
        $video['tag_url'] = array();
        if (!empty($video['tag_fmt'])) {
            $tagarr = array();
            $route_tag = $conf['route']['tag'] ?? '';
            foreach ($video['tag_fmt'] as $_tag => $_tagname) {
                $tagurl = url($route_tag . '-' . $_tag);
                $tagarr[] = array(
                    'name' => $_tagname,
                    'url' => $tagurl
                );
            }
            $video['tag_url'] = $tagarr;
        }
        if (!$light) {
            $video['play_data'] = \App\Service\Content\VideoService::playData((string)($video['play_from'] ?? ''), (string)($video['play_url'] ?? ''), (string)($cate['alias'] ?? ''), (int)$video['vid']);
            $video['play_data_json'] = xn_json_encode($video['play_data']);
        }
        return $video;
    }

    /**
     * 集合主题 vid 统一拉取（原注释：避免多次查询 video 表 = N+1 收敛，性能红线 §三.2）：
     * 入参两个行集键 stickylist（置顶）与 vidlist（普通）按 vid 为键做数组合并（**同键时 stickylist 优先**），
     * 抽 vid → array_unique → **一次** find 回表（pagesize 取集合大小，即全量取回），
     * 再过 video_list_access_filter（按 global $gid 的付费/等级可见性过滤）、
     * 最后 array2_sort_key 按传入顺序重排，包成 array('videolist' => ...) 返回。
     * ⚠ light 的审计修复口径（原代码注释在案）：listByCid 传下来的 light **此前被丢弃**，
     *   导致列表/block 热路径标称轻量却仍装配 play_data；现按 $arr['light'] 生效 ⇒
     *   要省大字段就在入参里显式带 light，默认 FALSE。
     * ⚠ 与 Art 侧同名方法的差别：Video 这支**不调用 safeInfo 洗敏**（Art 侧会剥 userip 等），
     *   对外裸输出前自行确认字段面。
     * @param array $arr 形如 array('stickylist' => 行集, 'vidlist' => 行集, 'light' => bool)，各键可省略
     * @return array|null 空输入 = NULL；否则 array('videolist' => 按传入顺序重排、已做可见性过滤的视频行集)
     */
    public static function unifiedPull($arr)
    {
        global $gid, $cid;
        $stickylist = array_value($arr, 'stickylist', array());
        $vidlist = array_value($arr, 'vidlist', array());
        $vidarrlist = $vidlist = $stickylist + $vidlist;
        $vidarr = empty($vidarrlist) ? array() : arrlist_values($vidarrlist, 'vid');
        if (empty($vidarr)) return NULL;
        $vidarr = array_unique($vidarr);
        // light 契约修复（审计）：listByCid 传入的 light 此前被丢弃，
        // 列表/block 热路径默认 light=TRUE 却始终装配 play_data
        $light = array_value($arr, 'light', FALSE);
        $arrlist = self::find($vidarr, count($vidarr), TRUE, $light);
        video_list_access_filter($arrlist, $gid);
        $videolist = array();
        foreach ($arrlist as $_vid => &$_video) {
            isset($vidlist[$_video['vid']]) and $videolist[$_vid] = $_video;
        }
        $videolist = array2_sort_key($videolist, $vidlist, 'vid');
        unset($arrlist, $vidlist);
        $arr = array('videolist' => $videolist);
        return $arr;
    }

    // 过滤安全数据
    // 注（插件系统改造）：原此处有 // hook video_filter_start/end.php 注释，为死点——
    // Trait 经 PSR-4 autoloader 直载，不经 _include() 编译管线，编译期钩子永不生效。
    // 钩子点只允许放在经 _include 编译的文件（core/model、route、core/admin/route、模板）。
    /**
     * 过滤安全数据（原注释口径）：**引用入参、就地 unset** 敏感列——
     * userip（发布者 IP）、cid / flagid / type（分类与标记内部字段）、user（整个作者子数组）、
     * create_date（入库时间）。用于对外输出前的洗敏，调用方传的必须是变量，
     * 传字面量或函数返回值会因「引用传递」直接报错。
     * 返回的就是被改过的同一个数组（没有副本），所以「取洗敏后的值」和「改原变量」是同一件事，
     * 别指望拿返回值做非破坏性清洗。
     * ⚠ 紧邻上方四行是插件系统改造的原始记录，逐行保留：本 Trait 走 PSR-4 直载、不经编译管线，
     *   所以那两处 hook 标记是**死点**（想在本函数前后插逻辑，请到 model 层的 video_filter() 壳那一侧）。
     * @param array $val 待洗敏的内容行（**引用传递**，就地删除上述键）
     * @return void 无返回值（底层实现不 return，效果全在引用副作用上）
     */
    public static function filter(&$val)
    {
        unset($val['userip'], $val['cid'], $val['flagid'], $val['type'], $val['user'], $val['create_date']);
    }
}
