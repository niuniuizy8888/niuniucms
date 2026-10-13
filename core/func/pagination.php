<?php
/**
 * Pagination 函数
 * 从 misc.php 拆分
 */

/**
 * 生成分页数组（数据结构化，非HTML输出）
 * @param string $url_tpl URL模板，{page} 会被替换为页码
 * @param int $totalnum 总记录数
 * @param int $page 当前页码
 * @param int $pagesize 每页条数
 * @return array 包含 current_page, total_page, first, last, prev, next, pages 等键
 * @note 2026-02-17 —— 此行原本是一条夹在本 docblock 与函数声明之间的裸日期注释
 *       （core/func/misc.php 的 [PAGINATION] 段落标记处留有同一日期），会顶掉本块与声明的
 *       IDE 关联，故并入块内保留痕迹；其确切含义仓库内无记载，勿据此推断改动内容。
 */
function page_array($url_tpl, $totalnum, $page, $pagesize = 20)
{
    $totalpage = max(1, ceil($totalnum / $pagesize));
    $page = max(1, min((int)$page, $totalpage));
    $shownum = 3;
    $start = max(1, $page - $shownum);
    $end   = min($totalpage, $page + $shownum);
    $right = $page + $shownum - $totalpage;
    if ($right > 0) {
        $start = max(1, $start - $right);
    }

    $left = $page - $shownum;
    if ($left < 0) {
        $end = min($totalpage, $end - $left);
    }
    // 构建中部页码数组
    $pages = [];
    for ($i = $start; $i <= $end; $i++) {
        $pages[] = [
            'page'    => $i,
            'url'     => str_replace('{page}', $i, $url_tpl),
            'current' => $i == $page,
        ];
    }
    return [
        'current_page' => $page,
        'total_page'   => $totalpage,
        'current_url'  => str_replace('{page}', $page, $url_tpl),
        'first'        => $page > 1 ? str_replace('{page}', 1, $url_tpl) : null,
        'last'         => $page < $totalpage ? str_replace('{page}', $totalpage, $url_tpl) : null,
        'prev'         => $page > 1 ? str_replace('{page}', $page - 1, $url_tpl) : null,
        'next'         => $page < $totalpage ? str_replace('{page}', $page + 1, $url_tpl) : null,
        'pages'        => $pages,
    ];
}

// ---------------------> encrypt function end
/**
 * 渲染分页条里的**单个页项 HTML**：把 $url/$text/$active 填进全局模板 $g_pagination_tpl
 * （占位符 {url}、{text}、{active}），模板为空时先落到出厂默认
 * `<li class="page-item{active}"><a href="{url}" class="page-link">{text}</a></li>`。
 * 该全局是**主题/插件可覆盖的换肤点**（在别处改写 $g_pagination_tpl 即可换分页外观），
 * 判定用的是 empty()，所以模板串被设成 '0' 这类假值会被重新写回出厂模板。
 * ⚠ 转义边界（红线 §二.4）：三个占位符都是**裸替换、不转义**，pagination() 传进来的是
 * 内部整数码与 url() 生成的地址故安全；若新调用方把用户可控值当 $text/$url 传进来，
 * 这里就是 XSS 注入点，必须由调用方自行 htmlspecialchars。
 * ⚠ 紧邻上方的「encrypt function end」是文件从 misc.php 拆分时留下的错位标记，与本函数无关。
 * @param string $url 已代入页码的完整链接（填进 href 位置）
 * @param string|int $text 页签显示文本（页码数字或「« / »」实体，取证：pagination() 的实参）
 * @param string $active 追加到 class 属性里的激活片段（pagination() 当前页传 ' active'，其余传空串）
 * @return string 单个分页项 HTML 片段
 */
function pagination_tpl($url, $text, $active = '')
{
    global $g_pagination_tpl;
    empty($g_pagination_tpl) and $g_pagination_tpl = '<li class="page-item{active}"><a href="{url}" class="page-link">{text}</a></li>';
    return str_replace(array('{url}', '{text}', '{active}'), array($url, $text, $active), $g_pagination_tpl);
}

/**
 * 生成前台分页条 HTML（page-number 风格；原注释「自定义翻页」）：首页/上一页 + 中部页码窗口 +
 * 下一页/尾页，当前页渲染成 span、其余是 a，窗口半径固定 3 页并在贴边时向另一侧补齐。
 *
 * 与 misc.php 的 pagination() 的分工：那个走 bootstrap 的 li/page-item 结构（经 pagination_tpl()
 * 出片，外观可被全局模板覆盖），本函数是**前台主题自带的一套硬编码 page-number 结构**，
 * 调用点是首页与列表引导（IndexService:61、misc.php 的后台/前台渲染链），换主题样式要改这里而不是模板。
 * 数据结构化版本请用同文件的 page_array()（模板层自己排版时用）。
 *
 * 口径与边界：
 *  - $url_tpl 是含 {page} 占位的 URL 模板，函数内 str_replace 出各页链接；不 urldecode、不转义，
 *    传 url()/ 生成的站内地址（红线 §二.4：这里的 href 是裸拼接，用户可控片段必须先由调用方处理）；
 *  - 总页数不足 2 页返回**空串**（调用方直接输出即可，不必判空）；
 *  - $page 会被 max/min 双向钳到 1..totalpage，比 pagination() 更宽容（后者只钳上界）。
 * @param string $url_tpl URL 模板，{page} 会被替换为页码
 * @param int $totalnum 总记录数
 * @param int $page 当前页码（1 起，越界自动钳制）
 * @param int $pagesize 每页条数，默认 20
 * @return string 分页条 HTML；不足两页时为空串
 */
function index_page($url_tpl, $totalnum, $page, $pagesize = 20)
{
    $totalpage = max(1, ceil($totalnum / $pagesize));
    $page = max(1, min((int)$page, $totalpage));
    $shownum = 3;
    if ($totalpage < 2) return '';
    $start = max(1, $page - $shownum);
    $end = min($totalpage, $page + $shownum);
    // 补足页码
    $right = $page + $shownum - $totalpage;
    $right > 0 && $start = max(1, $start - $right);
    $left = $page - $shownum;
    $left < 0 && $end = min($totalpage, $end - $left);
    $html = '';
    // 首页 / 上一页
    if ($page > 1) {
        $html .= '<a href="' . str_replace('{page}', 1, $url_tpl) . '" class="page-number page-previous" title="首页">首页</a>';
        $html .= '<a href="' . str_replace('{page}', $page - 1, $url_tpl) . '" class="page-number page-previous" title="上一页">上一页</a>';
    }
    // 中部页码
    for ($i = $start; $i <= $end; $i++) {
        if ($i == $page) {
            $html .= '<span class="page-number page-current desktop">' . $i . '</span>';
        } else {
            $html .= '<a href="' . str_replace('{page}', $i, $url_tpl) . '" class="page-number desktop" title="第' . $i . '页">' . $i . '</a>';
        }
    }
    // 下一页 / 尾页
    if ($page < $totalpage) {
        $html .= '<a href="' . str_replace('{page}', $page + 1, $url_tpl) . '" class="page-number page-next" title="下一页">下一页</a>';
        $html .= '<a href="' . str_replace('{page}', $totalpage, $url_tpl) . '" class="page-number page-next" title="尾页">尾页</a>';
    }
    return $html;
}

/**
 * 生成极简上下页分页HTML
 * @param string $url URL模板，{page} 会被替换为页码
 * @param int $totalnum 总记录数
 * @param int $page 当前页码
 * @param int $pagesize 每页条数
 * @return string 分页HTML
 */
// 简单的上一页，下一页，比较省资源，不用count(), 推荐使用，命名与 bootstrap 保持一致

// ============================================================================
// Block 模板分页渲染（从 core/lib/core.php 迁移）
// 由模板引擎 {block:block_*_page} 语法调用
// ============================================================================

/**
 * block模板分页：default风格
 * @param array $arr 参数（pagesize等）
 */

/**
 * block模板分页：pager风格
 * @param array $arr 参数（pagesize等）
 */

/**
 * block模板分页：bootstrap风格
 * @param array $arr 参数（pagesize等）
 */

/**
 * block模板分页：mui风格
 * @param array $arr 参数（pagesize等）
 */

/**
 * block模板分页：layui风格
 * @param array $arr 参数（pagesize等）
 */

