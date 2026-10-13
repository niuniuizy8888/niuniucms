<?php
/**
 * 中文分词提取关键词（供采集自动打标签，唯一消费方 ApiExecutionService 对标题打 tag）：
 * 空文本返回 ''；否则以 utf-8 实例化 PhpAnalysis（$loadInit=FALSE ⇒ 每次现载词典，重操作）
 * 分词，经 GetFinallyKeywords 过滤单字/短非英文词后取词，逗号拼接成串。
 * ⚠ 实际取词个数来自全局 $conf['tag_num']（缺省 5），形参 $num（默认 3）在当前实现中未参与
 *   任何计算——历史遗留，勿依赖它控制数量。返回未转义，入库/出模板前由调用方按上下文处理。
 * @param string $content 待分词文本（默认 ''）
 * @param int $num 遗留形参：实现未使用，改它无效
 * @return string 逗号分隔的关键词串（可空串）
 */
function get_tag($content = "",$num = 3) {
        global $conf;
        if (empty ( $content )) {
            return '';
        }
        \PhpAnalysis::$loadInit = false;
        $pa = new \PhpAnalysis ( 'utf-8', 'utf-8', false );
        $pa->LoadDict ();
        $pa->SetSource ($content);
        $pa->StartAnalysis ( true );
        $tags = $pa->GetFinallyKeywords ($conf['tag_num']??5); // 获取文章中的n个关键字
        return $tags;//返回关键字
    }
    