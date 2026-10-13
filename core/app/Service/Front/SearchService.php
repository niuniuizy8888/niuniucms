<?php
namespace App\Service\Front;

class SearchService
{
    /** 前台热搜词载荷门面：原样转发 Content\KeywordService::hotPayload($conf)，返回 ['payload'=>['items'=>热词条列表,'ttl'=>秒],'ttl'=>秒]（TTL 取 conf['search_cache_time'] 小时，非法/缺省回落 6h），供 route/search.php 的 cache_remember 形态写入。本层不加工、不缓存，纯为前台域保留一个入口。@param array $conf 站点配置（$_SERVER['conf'] 形态） @return array{payload:array,ttl:int} */
    public static function hotPayload($conf)
    {
        return \App\Service\Content\KeywordService::hotPayload($conf);
    }

    /**
     * 取搜索关键词（唯一权威口径，出口是**单层转义**文本）。
     *
     * 三段链路：① param($k,'',FALSE) 读**原始值**（跳过 param() 的默认 htmlspecialchars）；keyword 为空
     * 时回落取路由段 param(1) 并剥掉 .html 后缀；② 只解码一次——含 % 时先 rawurldecode，解不动才退
     * xn_urldecode（`_`→`%` 那套别名编码）；③ trim 后 htmlspecialchars(ENT_QUOTES,'UTF-8') 收尾。
     *
     * 为什么顺序不能调（反射型 XSS VULN-001）：旧版是「param() 先转义 → 再 rawurldecode」，双层编码
     * 载荷（%253Cscript%253E）解码后还原出裸 < > " 并经模板 {$keyword} 裸 echo 执行，还被 300s 页缓存
     * 与 search_hot 热词表放大成半持久化。转义必须是数据流的最后一步。
     *
     * ⚠ 返回值已带一层实体（如 &amp;），入库（recordKeyword）与出模板都不得再转一次（会出 &amp;amp;）。
     *
     * @return string 单层转义后的关键词；未提供时为空字符串（不会是 NULL）
     */
    public static function resolveKeyword()
    {
        // 上线前安全审计修复（反射型 XSS，VULN-001）：
        // 旧实现先经 param() htmlspecialchars、随后又 rawurldecode/xn_urldecode，
        // 双层编码载荷（%253Cscript%253E）可在解码后还原出原始 < > " 并经模板 {$keyword}
        // 裸输出执行（实测 /index.php?0=search&keyword=%253Cscript%253E... 正文反射成功，
        // 且被 page_cache 300s 与 search_hot 热词表放大为半持久化）。
        // 现改为「原始读取 → 只解码一次 → 收尾统一转义」：转义必须是最后一步。
        // 返回值语义与旧版一致（单转义文本），入库检索与模板输出两侧行为不变。
        $keyword = param('keyword', '', FALSE);
        if ($keyword == '' || $keyword === null) {
            $keyword = param(1, '', FALSE);
            if (!empty($keyword)) {
                $keyword = str_replace('.html', '', $keyword);
            }
        }
        $keyword = (string)$keyword;
        // URL 段解码：rewrite=0 时路由段取自 REQUEST_URI path（xn_url_parse 不解码），
        // 中文关键词到达此处为 %E6%80%92... 形态（与 TypeService 同批修复）
        if ($keyword !== '' && false !== strpos($keyword, '%')) {
            $decoded = rawurldecode($keyword);
            if ($decoded !== $keyword) {
                $keyword = $decoded;
            } else {
                $keyword = xn_urldecode($keyword);
            }
        }
        return htmlspecialchars(trim($keyword), ENT_QUOTES, 'UTF-8');
    }

    /** 搜索结果页数据装配：转 Content\QueryService::searchVideos()（ES 优先、前导通配 LIKE 回退）后整形成模板需要的键集。keyword 与 keyword_decode 当前是同值双键（历史模板两键并存，勿擅自合并）；num 恒等于**过滤后返回条数**（≤60），不是全库命中总数；adlist 现固定空数组（占位给模板）。⚠ $keyword 应是 resolveKeyword() 的转义出口，本方法不再转义也不做剥离。@param mixed $keyword 已转义的关键词 @param array $conf 站点配置（es_on 等） @return array {cid:int,keyword,keyword_decode,search_type:'like'|'mysql_fallback',videolist:array,adlist:array,num:int} */
    public static function build($keyword, $conf)
    {
        $keyword_decode = $keyword;
        $adlist = array();
        $query = \App\Service\Content\QueryService::searchVideos($keyword, $conf);
        $videolist = array_value($query, 'videolist', array());
        $search_type = array_value($query, 'search_type', 'like');
        $num = intval(array_value($query, 'num', count($videolist)));

        return array(
            'cid' => 0,
            'keyword' => $keyword,
            'keyword_decode' => $keyword_decode,
            'search_type' => $search_type,
            'videolist' => $videolist,
            'adlist' => $adlist,
            'num' => $num,
        );
    }

    /** 记录一次搜索热词（写 search_hot 表）：转发 Content\KeywordService::record($keyword)。副作用都在底层——截 64 字符、方言化 upsert、同 IP 同词 60s 写放大抑制（ThrottleService APCu 计数，被限流静默返回）、表缺失静默跳过，因此调用方不判返回值。⚠ 入参必须是 resolveKeyword() 已单层转义的文本，再转义即双重编码（record 内部也不转）。@param mixed $keyword 关键词 @return void */
    public static function recordKeyword($keyword)
    {
        \App\Service\Content\KeywordService::record($keyword);
    }
}
