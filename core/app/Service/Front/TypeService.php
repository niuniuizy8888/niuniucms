<?php
namespace App\Service\Front;

class TypeService
{
    /**
     * 筛选 URL 装配（原 core.php type_urls 下沉）
     * @param int $cid 栏目 ID
     * @param array $conf 站点配置
     * @param array $areaarray 地区列表
     * @param array $yeararray 年份列表
     * @param array $langarray 语言列表
     * @param string $req_area 当前地区筛选
     * @param string $req_lang 当前语言筛选
     * @param string $req_year 当前年份筛选
     * @return array
     */
    public static function typeUrls($cid, $conf, $areaarray, $yeararray, $langarray, $req_area, $req_lang, $req_year)
    {
        $route_type = $conf['route']['type'];
        $type_urls = array();
        // ⚠ 空项兜底过滤（数据层可能残留坏值）：后台设置曾把逗号列表按字节截断（area 100/lang 50/year 20），
        //   尾随逗号 explode 出空元素 ⇒ 前台筛选行渲染出一个**空白 pill**（实测 lists/types 页）。
        //   即使库里已是坏值也不该出现在页面上，故此处再滤一遍（配置修正见 SetManageWebsiteTrait::saveWebsite）。
        $areaarray = self::cleanList($areaarray);
        $yeararray = self::cleanList($yeararray);
        $langarray = self::cleanList($langarray);
        foreach (['area' => $areaarray, 'year' => $yeararray, 'lang' => $langarray] as $type => $arr) {
            foreach ($arr as $val) {
                $params = [
                    'area' => $type == 'area' ? $val : $req_area,
                    'year' => $type == 'year' ? $val : $req_year,
                    'lang' => $type == 'lang' ? $val : $req_lang
                ];
                $area = isset($params['area']) && $params['area'] !== '' ? $params['area'] : '';
                $year = isset($params['year']) && $params['year'] !== '' ? $params['year'] : '';
                $lang = isset($params['lang']) && $params['lang'] !== '' ? $params['lang'] : '';
                // URL 构造逻辑（req_* 已收尾转义；此处再过 xn_urlencode 保证段结构不可被 _ 分隔与引号破坏）
                $urls = $cid . '_' . rawurlencode($area) . '_' . rawurlencode($year) . '_' . rawurlencode($lang);
                $url = url($route_type . '-' . $urls, '', FALSE);

                $type_urls[$type][] = ['name' => $val, 'url' => $url];
            }
        }
        return $type_urls;
    }

    /**
     * 筛选值列表清洗：trim + 去空项 + 去重（保序）。用于地区/年份/语言三组——它们来自
     * `explode(',', $conf['area'|'year'|'lang'])`，配置里一个尾随逗号就会多出一个空项，
     * 模板按项渲染时会变成一个**空白 pill**（实测 symptom）。空项一律不参与渲染。
     * @param array $arr 原始列表（可能含空串/空白串/重复项）
     * @return array 清洗后的顺序数组
     */
    private static function cleanList($arr): array
    {
        $out = array();
        foreach ((array)$arr as $v) {
            $v = trim((string)$v);
            if ('' === $v || in_array($v, $out, TRUE)) continue;
            $out[] = $v;
        }
        return $out;
    }

    /**
     * 前台 type 筛选页数据装配（route/type.php 的唯一取数入口，返回整套模板变量供 extract）。
     * 流程：解析路由段 cid_area_year_lang（`_` 分隔、xn_urlencode 自定义形态）→ 解码（标准
     * rawurldecode 优先，失败回落 xn_urldecode）→ **收尾统一 htmlspecialchars(ENT_QUOTES)**。
     * ⚠ 转义纪律（安全审计 F-01 反射型 XSS，与 SearchService::resolveKeyword 同口径）：解码会把
     * param() 入口转义过的载荷还原成原始字符，且模板 {$var} 输出层不转义（§二.4），因此这里的
     * htmlspecialchars 是数据流最后一步、不可前移不可省略；三值随后拼入 seo.t/k/d（title/meta）与
     * page_url/type_urls（href），URL 拼接点另用 xn_urlencode 包裹。
     * cid 三级回退：路由段 cid > $fallbackCid > $catelist 首个 category==1 栏目。
     * 产出含计数（VideoService::count）与分页列表（QueryService::typeVideoList，内部按 $gid
     * 受限栏目过滤）；sonlist 为全量非频道子分类（上限 2000，内存过滤）。
     * @param string $type 路由段原文（如 "1_国产_2024_国语"；可空）
     * @param int $page 页码（调用方已钳制）
     * @param int $fallbackCid 兜底分类 id（?cid= 参数）
     * @param array $conf 站点配置（读 route.type/pagesize）
     * @param array $catelist 全部分类数组
     * @param array $areaarray 地区候选（explode(conf.area)）
     * @param array $yeararray 年份候选
     * @param array $langarray 语言候选
     * @return array 视图变量集：cid/cate/req_area/req_year/req_lang/type_urls/sonlist/num/page/pagesize/page_url/videolist/index_page/adlist
     */
    public static function build($type, $page, $fallbackCid, $conf, $catelist, $areaarray, $yeararray, $langarray)
    {
        $parts = $type ? explode('_', $type) : array();
        $parts = array_pad($parts, 4, '');
        $cid = intval($parts[0]);
        if (empty($cid)) {
            $cid = intval($fallbackCid);
        }
        if (empty($cid)) {
            foreach ($catelist as $v) {
                if (isset($v['category']) && intval($v['category']) == 1) {
                    $cid = intval($v['cid']);
                    break;
                }
            }
        }

        $req_area = isset($parts[1]) ? $parts[1] : '';
        $req_year = isset($parts[2]) ? $parts[2] : '';
        $req_lang = isset($parts[3]) ? $parts[3] : '';
        // URL 段解码：rewrite=0 时路由段取自 REQUEST_URI path（xn_url_parse 不解码），
        // 中文筛选项到达此处为 %E4%B8%AD... 形态；先按标准 URL 编码还原，再用 xn_urldecode 兼容自定义 _5f 表示
        foreach (array('req_area' => &$req_area, 'req_lang' => &$req_lang) as $k => &$v) {
            if ($v !== '' && false !== strpos($v, '%')) {
                $decoded = rawurldecode($v);
                if ($decoded !== $v) {
                    $v = $decoded;
                } else {
                    $v = xn_urldecode($v);
                }
            }
        }
        unset($v);
        // 上线前安全审计修复（反射型 XSS，F-01，与 SearchService::resolveKeyword 同病根）：
        // 上面一步把 %22%3E%3Cscript%3E 类载荷解码还原出原始 " < >，而 param() 的 htmlspecialchars
        // 发生在解码之前已空转——若此后不再转义，载荷经 SeoService::apply 拼入 seo.t/k/d（title/meta
        // 上下文）与 typeUrls/page_url（href 属性上下文）后由模板 {$var} 裸输出执行（线上实测
        // ?0=types&1=1_%2522%253E%253Cscript%253E... 返回 2 处未转义可执行标签，page_cache 1800s 放大）。
        // 修复口径与 resolveKeyword 一致：解码（转义前）→ 收尾统一 htmlspecialchars(ENT_QUOTES)，
        // 转义必须是数据流最后一步；URL 拼接点另用 xn_urlencode 包裹（见下方三处），保证 href 上下文不可逃逸。
        $req_area = htmlspecialchars($req_area, ENT_QUOTES, 'UTF-8');
        $req_year = htmlspecialchars($req_year, ENT_QUOTES, 'UTF-8');
        $req_lang = htmlspecialchars($req_lang, ENT_QUOTES, 'UTF-8');
        $cate = $cid ? \App\Service\Content\CateService::read($cid) : array();
        $pagesize = $conf['pagesize'];
        $extra = array();
        $sonlist = arrlist_cond_orderby($catelist, array('category' => 0), array(), 1, 2000);
        $type_urls = \App\Service\Front\TypeService::typeUrls($cid, $conf, $areaarray, $yeararray, $langarray, $req_area, $req_lang, $req_year);
        if (!isset($type_urls['cate'])) {
            $type_urls['cate'] = array();
        }

        $route_type = $conf['route']['type'];
        $parent = 0;
        if (!empty($cate)) {
            $parent = intval(array_value($cate, 'cup', 0)) ? intval(array_value($cate, 'cup', 0)) : intval(array_value($cate, 'cid', 0));
        } else {
            $parent = $cid;
        }

        if ($parent) {
            $arr = array();
            foreach ($catelist as $v) {
                intval(array_value($v, 'cup', 0)) == $parent && intval(array_value($v, 'category', 0)) == 0 && $arr[] = $v;
            }
            $arr = arrlist_multisort($arr, 'cid', true);
            foreach ($arr as $v) {
                $cid2 = intval(array_value($v, 'cid', 0));
                $name2 = array_value($v, 'name', '');
                if (empty($cid2) || empty($name2)) continue;
                $urls = $cid2 . '_' . rawurlencode($req_area) . '_' . rawurlencode($req_year) . '_' . rawurlencode($req_lang);
                $type_urls['cate'][] = array('id' => strval($cid2), 'name' => $name2, 'url' => url($route_type . '-' . $urls, '', false));
            }
        }

        $cids = array();
        $cid and $cids[] = $cid;
        if (!empty($cid) && !empty($cate) && intval(array_value($cate, 'category', 0)) == 1) {
            foreach ($catelist as $v) {
                if (intval(array_value($v, 'cup', 0)) == $cid) {
                    $cids[] = intval(array_value($v, 'cid', 0));
                }
            }
            $cids = array_values(array_filter(array_unique($cids)));
        }

        $where = array_filter_empty(array(
            'area' => $req_area,
            'lang' => $req_lang,
            'year' => $req_year,
            'cid' => $cids ? $cids : $cid
        ));
        $urls0 = $cid . '_' . rawurlencode($req_area) . '_' . rawurlencode($req_year) . '_' . rawurlencode($req_lang);
        $num = \App\Service\Content\VideoService::count($where);
        $page_url = url($route_type . '-' . $urls0 . '-{page}', $extra, false);
        $videolist = \App\Service\Content\QueryService::typeVideoList($where, $page);

        return array(
            'cid' => $cid,
            'cate' => $cate,
            'req_area' => $req_area,
            'req_year' => $req_year,
            'req_lang' => $req_lang,
            'type_urls' => $type_urls,
            'sonlist' => $sonlist,
            'num' => $num,
            'page' => $page,
            'pagesize' => $pagesize,
            'page_url' => $page_url,
            'videolist' => $videolist,
            'index_page' => '',
            'adlist' => array(),
        );
    }
}
