<?php
namespace App\Service\Front;

/**
 * 前台 types 筛选页（route/type.php 唯一取数入口）。
 * URL：?types-{cid}_{area}_{year}_{lang}[_{sort}][-{page}].html
 *  - 空段保位；sort ∈ {''(=最新), hot}；year 支持 'YYYY'（精确）与 '~YYYY'（≤YYYY，「更早」；不用 '-'，它是页码分隔符）。
 *  - 选项值来自库内真实数据（facets），同义词/多值字段在 PHP 侧展开成「原始值 IN 列表」，
 *    查询仍走等值索引（不做 LIKE 全表扫）。
 *  - 非法选项值 / 不存在的 cid ⇒ not_found=1（路由回 404）；越界页 ⇒ redirect（302 末页）。
 * 2026-10-19 筛选审计 14 项改造（备份 /home/www/nncms_bak/filter-20261019/）。
 */
class TypeService
{
    /** 排序白名单：段值 => [显示名, 排序字段]。库内 douban_score 仅 1 条有值 ⇒ 不提供「评分」。 */
    public const SORTS = array(
        '' => array('最新', 'vid'),
        'hot' => array('最热', 'views'),
    );

    /** 地区/语言同义词：归一名 => 等价写法（多值字段按 , / 、 拆分后逐段匹配）。 */
    private const SYN = array(
        'lang' => array(
            '国语' => array('国语', '汉语普通话', '普通话', '华语', '汉语', '国语对白', '汉语普通话Manda', '普通话Manda', 'Mandarin'),
            '粤语' => array('粤语', '广东话', '粤语对白', 'Cantonese'),
            '闽南语' => array('闽南语', '台语'),
            '英语' => array('英语', 'English', '英文'),
            '韩语' => array('韩语', '韩国语'),
            '日语' => array('日语', '日本语'),
        ),
        'area' => array(
            '中国大陆' => array('中国大陆', '大陆', '内地', '国产', '中国'),
            '中国香港' => array('中国香港', '香港', '港台'),
            '中国台湾' => array('中国台湾', '台湾', '港台'),
            '美国' => array('美国', 'USA'),
            '韩国' => array('韩国', '南韩'),
        ),
    );

    private const YEAR_SHOW = 6;     // 年份行显示最近 N 个，其余归入「更早」
    private const OPT_SHOW = 12;     // 地区/语言主行最多 N 项，其余进「更多」
    private const AUTO_MIN = 10;     // 配置未列的库内值，条数 ≥ 此值才自动补入
    private const FACET_TTL = 1800;  // 统计缓存（另有版本键主动失效，见 bumpFacets）

    /** 兼容旧调用（原 core.php type_urls 下沉）：按配置列表出链接（不再被 build 使用）。 */
    public static function typeUrls($cid, $conf, $areaarray, $yeararray, $langarray, $req_area, $req_lang, $req_year)
    {
        $route_type = $conf['route']['type'];
        $out = array();
        foreach (array('area' => $areaarray, 'year' => $yeararray, 'lang' => $langarray) as $type => $arr) {
            foreach (self::cleanList($arr) as $val) {
                $p = array('area' => $req_area, 'year' => $req_year, 'lang' => $req_lang);
                $p[$type] = $val;
                $out[$type][] = array('name' => $val, 'url' => self::url($route_type, $cid, $p['area'], $p['year'], $p['lang'], ''));
            }
        }
        return $out;
    }

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

    /** 统一 URL 构造：空 sort 省略第 5 段（兼容旧 4 段链接）；page>1 追加 -page；page='{page}' 出模板。 */
    public static function url($route_type, $cid, $area, $year, $lang, $sort, $page = 1)
    {
        $seg = intval($cid) . '_' . rawurlencode((string)$area) . '_' . rawurlencode((string)$year) . '_' . rawurlencode((string)$lang);
        if ((string)$sort !== '') $seg .= '_' . rawurlencode((string)$sort);
        if ($page === '{page}') return url($route_type . '-' . $seg . '-{page}', '', FALSE);
        $page = intval($page);
        return url($route_type . '-' . $seg . ($page > 1 ? '-' . $page : ''), '', FALSE);
    }

    /** 原始值 → 归一名列表（多值拆分 + 同义词；港台同时归香港/台湾）。 */
    private static function canonList($field, $raw)
    {
        $out = array();
        foreach (preg_split('#\s*[,，/、|]\s*#u', trim((string)$raw)) as $part) {
            if ($part === '' || $part === '0' || $part === '暂无' || $part === '未知') continue;
            $hit = FALSE;
            foreach (self::SYN[$field] ?? array() as $name => $list) {
                if (in_array($part, $list, TRUE)) { $out[$name] = 1; $hit = TRUE; }
            }
            $hit || $out[$part] = 1;
        }
        return array_keys($out);
    }

    /** 统计缓存版本键：分类缓存清理 / 视频导入 / 删除 / 回收站还原时调用，下次请求重算。 */
    public static function bumpFacets()
    {
        static $done = FALSE;
        if ($done || !function_exists('cache_set')) return;
        $done = TRUE;
        cache_set('nn_type_facet_ver', strval(microtime(TRUE)), 0);
    }

    /** 栏目树内三字段原始值分布：['area'=>[raw=>n],'year'=>..,'lang'=>..]。 */
    private static function rawFacets(array $cids)
    {
        static $memo = array();
        $cids = array_values(array_filter(array_map('intval', $cids)));
        sort($cids);
        $ver = function_exists('cache_get') ? strval(cache_get('nn_type_facet_ver')) : '';
        $key = 'nn_type_facets_' . md5(implode(',', $cids) . '|' . $ver);
        if (isset($memo[$key])) return $memo[$key];
        $c = function_exists('cache_get') ? cache_get($key) : NULL;
        if (is_array($c) && isset($c['lang'])) return $memo[$key] = $c;
        $out = array('area' => array(), 'year' => array(), 'lang' => array());
        $db = $_SERVER['db'];
        $tb = (isset($db->tablepre) ? $db->tablepre : 'nncms_') . 'vod';
        $cond = $cids ? ' WHERE cid IN (' . implode(',', $cids) . ')' : '';
        foreach (array('area', 'year', 'lang') as $f) {
            foreach ((array)$db->sql_find("SELECT `$f` AS v, COUNT(*) AS n FROM `$tb`$cond GROUP BY `$f`") as $r) {
                $out[$f][(string)$r['v']] = intval($r['n']);
            }
        }
        function_exists('cache_set') && cache_set($key, $out, self::FACET_TTL);
        return $memo[$key] = $out;
    }

    /** 归一名 => 条数（多值记录对每个归一名各计一次）。 */
    private static function canonCounts($field, array $raw)
    {
        $out = array();
        foreach ($raw as $v => $n) {
            if ($field === 'year') {
                if (preg_match('/^(19|20)\d\d$/', trim((string)$v))) $out[trim((string)$v)] = ($out[trim((string)$v)] ?? 0) + $n;
                continue;
            }
            foreach (self::canonList($field, $v) as $name) $out[$name] = ($out[$name] ?? 0) + $n;
        }
        return $out;
    }

    /** 选中值 → 命中的原始值 IN 列表（空数组 = 无匹配）。 */
    private static function matchRaw($field, $sel, array $raw)
    {
        $hit = array();
        foreach ($raw as $v => $n) {
            $v = (string)$v;
            if ($field === 'year') {
                $y = trim($v);
                if (!preg_match('/^(19|20)\d\d$/', $y)) continue;
                if ($sel[0] === '~' ? intval($y) <= intval(substr($sel, 1)) : $y === $sel) $hit[] = $v;
                continue;
            }
            if ($v === $sel || in_array($sel, self::canonList($field, $v), TRUE)) $hit[] = $v;
        }
        return $hit;
    }

    /** 默认频道 cid（影片最多的频道（?types.html 无 cid 时的默认）。 */
    public static function defaultCid(array $catelist)
    {
        $db = $_SERVER['db'];
        $tb = (isset($db->tablepre) ? $db->tablepre : 'nncms_') . 'vod';
        $ver = function_exists('cache_get') ? strval(cache_get('nn_type_facet_ver')) : '';
        $key = 'nn_type_cidnum_' . md5($ver);
        $cnt = function_exists('cache_get') ? cache_get($key) : NULL;
        if (!is_array($cnt)) {
            $cnt = array();
            foreach ((array)$db->sql_find("SELECT cid, COUNT(*) AS n FROM `$tb` GROUP BY cid") as $r) $cnt[intval($r['cid'])] = intval($r['n']);
            function_exists('cache_set') && cache_set($key, $cnt, self::FACET_TTL);
        }
        // 取影片最多的频道（「首个有数据」会落到排序靠前但只有几条的小频道，如伦理片 3 条）
        $first = 0; $best = 0; $bestN = 0;
        foreach ($catelist as $v) {
            if (intval($v['category'] ?? 0) != 1) continue;
            $c = intval($v['cid']);
            $first || $first = $c;
            $n = $cnt[$c] ?? 0;
            foreach ($catelist as $s) if (intval($s['cup'] ?? 0) == $c) $n += $cnt[intval($s['cid'])] ?? 0;
            if ($n > $bestN) { $best = $c; $bestN = $n; }
        }
        return $best ?: $first;
    }

    /** 路由段解码 + 收尾转义（XSS F-01 口径：解码后 htmlspecialchars 是最后一步）。 */
    private static function seg($v)
    {
        $v = (string)$v;
        if ($v !== '' && FALSE !== strpos($v, '%')) {
            $d = rawurldecode($v);
            $v = $d !== $v ? $d : xn_urldecode($v);
        }
        return htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8');
    }

    /**
     * 筛选页数据装配。返回模板变量（extract）；另含 not_found / redirect 两个控制键。
     * @return array
     */
    public static function build($type, $page, $fallbackCid, $conf, $catelist, $areaarray, $yeararray, $langarray)
    {
        $rt = $conf['route']['type'];
        $parts = array_pad($type !== '' ? explode('_', (string)$type) : array(), 5, '');
        $cid = intval($parts[0]) ?: intval($fallbackCid);
        $cid || $cid = self::defaultCid($catelist);
        $req_area = self::seg($parts[1]);
        $req_year = self::seg($parts[2]);
        $req_lang = self::seg($parts[3]);
        $req_sort = self::seg($parts[4]);
        $page = max(1, intval($page));
        $base = array('cid' => $cid, 'not_found' => 0, 'redirect' => '');

        $cate = ($cid && isset($catelist[$cid])) ? \App\Service\Content\CateService::read($cid) : array();
        if (empty($cate)) { $base['not_found'] = 1; return $base; }

        // 频道 = 自身 + 直属子栏目；子栏目 = 自身
        $parent = intval($cate['cup'] ?? 0) ?: $cid;
        $cids = array($cid);
        if (intval($cate['category'] ?? 0) == 1) {
            foreach ($catelist as $v) intval($v['cup'] ?? 0) == $cid && $cids[] = intval($v['cid']);
        }
        $cids = array_values(array_unique(array_filter($cids)));

        // ---- 参数校验（非法 ⇒ 404，避免无限组合被收录） ----
        $raw = self::rawFacets($cids);
        $cnt = array('area' => self::canonCounts('area', $raw['area']), 'year' => self::canonCounts('year', $raw['year']), 'lang' => self::canonCounts('lang', $raw['lang']));
        $okVal = function ($f, $v) use ($cnt, $areaarray, $langarray) {
            if ($v === '') return TRUE;
            if ($f === 'year') return (bool)preg_match('/^~?(19|20)\d\d$/', $v);
            $conf_list = $f === 'area' ? self::cleanList($areaarray) : self::cleanList($langarray);
            return isset($cnt[$f][$v]) || in_array($v, $conf_list, TRUE) || isset(self::SYN[$f][$v]);
        };
        if (!$okVal('area', $req_area) || !$okVal('year', $req_year) || !$okVal('lang', $req_lang) || !isset(self::SORTS[$req_sort])) {
            $base['not_found'] = 1;
            return $base;
        }

        // ---- 查询条件：选中值展开为原始值 IN 列表（同义 + 多值 + 年份区间），仍走等值索引 ----
        $where = array('cid' => count($cids) > 1 ? $cids : $cid);
        $empty = FALSE;
        foreach (array('area' => $req_area, 'year' => $req_year, 'lang' => $req_lang) as $f => $v) {
            if ($v === '') continue;
            $m = self::matchRaw($f, $v, $raw[$f]);
            $m ? $where[$f] = $m : $empty = TRUE;
        }
        $pagesize = max(1, intval($conf['pagesize'] ?? 20));
        $num = $empty ? 0 : intval(\App\Service\Content\VideoService::count($where));
        $totalpage = max(1, (int)ceil($num / $pagesize));
        if ($page > $totalpage) {
            $base['redirect'] = self::url($rt, $cid, $req_area, $req_year, $req_lang, $req_sort, $totalpage);
            return $base;
        }
        $videolist = array();
        if ($num > 0) {
            global $gid;
            $order = array(self::SORTS[$req_sort][1] => -1);
            $order['vid'] = -1;
            $videolist = \App\Repository\RepositoryFactory::vod()->find($where, $order, $page, $pagesize, 'vid');
            foreach ($videolist as &$video) \App\Service\Content\VideoService::format($video);
            unset($video);
            \App\Service\Content\VideoService::listAccessFilter($videolist, $gid);
        }

        // ---- 选项行 ----
        $u = function ($a, $y, $l, $s = NULL, $c = NULL) use ($rt, $cid, $req_sort) {
            return self::url($rt, $c === NULL ? $cid : $c, $a, $y, $l, $s === NULL ? $req_sort : $s);
        };
        $type_urls = array('cate' => array(), 'area' => array(), 'year' => array(), 'lang' => array(), 'sort' => array());
        $type_more = array('area' => array(), 'lang' => array());
        foreach ($catelist as $v) {
            if (intval($v['cup'] ?? 0) != $parent || intval($v['category'] ?? 0) != 0 || empty($v['name'])) continue;
            $type_urls['cate'][] = array('id' => strval($v['cid']), 'name' => $v['name'], 'url' => $u($req_area, $req_year, $req_lang, NULL, intval($v['cid'])));
        }
        usort($type_urls['cate'], function ($a, $b) { return intval($a['id']) <=> intval($b['id']); });

        foreach (array('area' => self::cleanList($areaarray), 'lang' => self::cleanList($langarray)) as $f => $conf_list) {
            $sel = $f === 'area' ? $req_area : $req_lang;
            $opts = array();
            foreach ($cnt[$f] as $name => $n) {
                $name = (string)$name;
                if ($name === '其它' || $name === '其他') continue;
                if ($n >= self::AUTO_MIN || (in_array($name, $conf_list, TRUE) && $n > 0) || $name === $sel) $opts[$name] = $n;
            }
            $sel !== '' && !isset($opts[$sel]) && $opts[$sel] = 0;
            arsort($opts);
            $i = 0;
            foreach ($opts as $name => $n) {
                $p = array('area' => $req_area, 'year' => $req_year, 'lang' => $req_lang);
                $p[$f] = $name;
                $item = array('name' => htmlspecialchars($name, ENT_QUOTES, 'UTF-8'), 'num' => $n, 'url' => $u($p['area'], $p['year'], $p['lang']));
                if (++$i <= self::OPT_SHOW || $name === $sel) $type_urls[$f][] = $item; else $type_more[$f][] = $item;
            }
            if (in_array('其它', $conf_list, TRUE) && (($cnt[$f]['其它'] ?? 0) > 0 || $sel === '其它')) {
                $p = array('area' => $req_area, 'year' => $req_year, 'lang' => $req_lang);
                $p[$f] = '其它';
                $type_urls[$f][] = array('name' => '其它', 'num' => $cnt[$f]['其它'] ?? 0, 'url' => $u($p['area'], $p['year'], $p['lang']));
            }
        }

        $years = $cnt['year'];
        krsort($years, SORT_NUMERIC);
        $yk = array_keys($years);
        $shown = array_slice($yk, 0, self::YEAR_SHOW);
        foreach ($shown as $y) $type_urls['year'][] = array('name' => strval($y), 'url' => $u($req_area, strval($y), $req_lang));
        if ($req_year !== '' && $req_year[0] !== '~' && !in_array($req_year, array_map('strval', $shown), TRUE)) {
            $type_urls['year'][] = array('name' => $req_year, 'url' => $u($req_area, $req_year, $req_lang));
        }
        if (count($yk) > self::YEAR_SHOW) {
            $tok = '~' . (intval(end($shown)) - 1);
            $type_urls['year'][] = array('name' => '更早', 'url' => $u($req_area, $tok, $req_lang), 'token' => $tok);
            $type_urls['year_early'] = $tok;
        }
        $year_early = $type_urls['year_early'] ?? '';
        unset($type_urls['year_early']);
        // 「更早」高亮：当前值是任意 YYYY- 区间
        $year_is_range = ($req_year !== '' && $req_year[0] === '~') ? 1 : 0;

        foreach ($type_urls['year'] as &$yv) {
            $yv['on'] = (isset($yv['token']) ? $year_is_range : ($req_year === $yv['name'])) ? 1 : 0;
        }
        unset($yv);
        foreach (self::SORTS as $k => $v) $type_urls['sort'][] = array('key' => $k, 'name' => $v[0], 'url' => $u($req_area, $req_year, $req_lang, $k));

        $type_reset = array(
            'cate' => $u($req_area, $req_year, $req_lang, NULL, $parent),
            'area' => $u('', $req_year, $req_lang),
            'year' => $u($req_area, '', $req_lang),
            'lang' => $u($req_area, $req_year, ''),
            'all' => self::url($rt, $cid, '', '', '', ''),
        );
        $chips = array();
        $req_area !== '' && $chips[] = array('name' => $req_area, 'url' => $type_reset['area']);
        $req_year !== '' && $chips[] = array('name' => $year_is_range ? (substr($req_year, 1) . '年及以前') : $req_year, 'url' => $type_reset['year']);
        $req_lang !== '' && $chips[] = array('name' => $req_lang, 'url' => $type_reset['lang']);
        $req_sort !== '' && $chips[] = array('name' => self::SORTS[$req_sort][0], 'url' => $u($req_area, $req_year, $req_lang, ''));

        // ---- SEO：≥2 条件 / 排序 / 翻页 ⇒ noindex,follow；canonical 指向栏目首页或单条件页 ----
        $ncond = ($req_area !== '') + ($req_year !== '') + ($req_lang !== '');
        $noindex = ($ncond >= 2 || $req_sort !== '' || $page > 1 || $num == 0) ? 1 : 0;
        $canonical = $ncond == 1 ? self::url($rt, $cid, $req_area, $req_year, $req_lang, '') : $type_reset['all'];

        $page_url = self::url($rt, $cid, $req_area, $req_year, $req_lang, $req_sort, '{page}');
        $index_page = $num > $pagesize ? index_page($page_url, $num, $page, $pagesize) : '';
        // 第 1 页链接去掉 -1 后缀，与 canonical/无页码 URL 统一（避免重复页）
        $index_page && $index_page = str_replace('"' . str_replace('{page}', '1', $page_url) . '"', '"' . self::url($rt, $cid, $req_area, $req_year, $req_lang, $req_sort, 1) . '"', $index_page);

        return array(
            'cid' => $cid, 'cate' => $cate, 'not_found' => 0, 'redirect' => '',
            'req_area' => $req_area, 'req_year' => $req_year, 'req_lang' => $req_lang, 'req_sort' => $req_sort,
            'type_urls' => $type_urls, 'type_more' => $type_more, 'type_reset' => $type_reset, 'type_parent' => $parent,
            'year_is_range' => $year_is_range, 'year_early' => $year_early,
            'chips' => $chips, 'chip_n' => count($chips), 'filter_noindex' => $noindex, 'filter_canonical' => $canonical,
            'sonlist' => array(), 'num' => $num, 'page' => $page, 'pagesize' => $pagesize, 'totalpage' => $totalpage,
            'page_url' => $page_url, 'videolist' => $videolist, 'index_page' => $index_page, 'adlist' => array(),
        );
    }
}