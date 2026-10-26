<?php
namespace App\Service\Admin;

class LinkManageService
{
    /**
     * 友情链接管理页视图数据：新增表单控件 + 列表 + 分页条 + 'admin_link' 场景 token。
     * ⚠ 列表取数 link_get($page, $n) 把**总条数**当 pagesize 传给 LinkService::listCache——
     * 第 1 页等于全量拉取，翻页（page≥2）OFFSET=total 之后恒空；分页条按 $pagesize=20 生成，
     * 两处口径不一致（见任务汇报，未在此擅自修）。
     * @param int $uid 当前管理员 uid（token 绑定）
     * @return array input/arrlist/pagination/header/safe_token
     */
    public static function listPage(int $uid): array
    {
        $page = max(1, intval(param('page', 1)));
        $pagesize = 20;
        // 上线前审计修复（VULN-M10）：分页上界钳制——消除无界 OFFSET 索引行走
        $page = min($page, 10000);
        $extra = array('page' => '{page}');
        $n = link_count();

        return array(
            'input' => array(
                'name' => form_text('name', '', FALSE, lang('site_name')),
                'url' => form_text('url', '', FALSE, lang('site_url')),
            ),
            'arrlist' => link_get($page, $n),
            'pagination' => pagination(url('link', $extra, TRUE), $n, $page, $pagesize),
            'header' => array(
                'title' => lang('friends_link'),
                'mobile_title' => lang('friends_link'),
                'mobile_link' => url('link', '', TRUE),
            ),
            'safe_token' => bull_token_set($uid, 'admin_link'),
        );
    }

    /**
     * 新增友链（POST 写路径，type=1）：admin_link 场景 token 闸（fail-closed）→
     * name 走 admin_param_text(24) + filter_all_html 入库净化（防后台存储型 XSS）；
     * 名称净化后为空即拒；url 走 normalizeUrl（http(s) 前缀 + xn_url_strip 去引号/尖括号），
     * 填了但不合法即拒（不再静默存空）→ link_create（底层 REPLACE INTO，载荷不含主键 id，
     * 故撞不到已有行、等价 INSERT）。
     * @param int $uid 操作者 uid
     * @param int $time 请求时间戳（create_date）
     * @return array 落库的 {name, url}；写失败经 message() 终止请求
     */
    public static function create(int $uid, int $time): array
    {
        self::verifyToken($uid);

        $name = trim(filter_all_html(admin_param_text('name', '', 24)));
        '' === $name and message('name', '友链名称不能为空（HTML 标签会被过滤）');
        $rawUrl = admin_param_text('url', '', 120);
        $url = self::normalizeUrl($rawUrl);
        '' !== $rawUrl && '' === $url and message('url', '友链地址必须以 http:// 或 https:// 开头');
        FALSE === link_create(array('name' => $name, 'url' => $url, 'create_date' => $time)) and message(-1, lang('create_failed'));

        admin_audit_log('link_create', array('name' => $name));
        return array('name' => $name, 'url' => $url);
    }

    /**
     * 批量调整友链排序（POST 写路径，type=2）：token 闸 → sortPayload 解析 _POST['data'] →
     * LinkService::sortBatch 逐行 UPDATE（仅 rank≠oldrank 的行写库并清列表缓存；返回的变更数未在此消费）→ 审计。
     * @param int $uid 操作者 uid
     * @return array 解析后的行集 {id,oldrank,rank}[]（提交原样，含被 sortBatch 跳过的未变更行）
     */
    public static function sort(int $uid): array
    {
        self::verifyToken($uid);

        $rows = self::sortPayload();
        empty($rows) && message(1, lang('data_is_empty'));
        \App\Service\Content\LinkService::sortBatch($rows);

        admin_audit_log('link_sort', array('data' => $rows));
        return $rows;
    }

    /**
     * 删除友链（POST 写路径，type≠1/2）：token 闸 → admin_param_int('id')（缺省 0）→
     * link_delete。id<=0 时底层空参短路返回 FALSE ⇒ message(-1) 终止请求；
     * 行确实不存在时底层返回 rowCount=0，`FALSE === 0` 不成立 ⇒ 按成功处理（幂等口径）。
     * @param int $uid 操作者 uid
     * @return int 已删的链接 id
     */
    public static function delete(int $uid): int
    {
        self::verifyToken($uid);

        $id = admin_param_int('id');
        FALSE === link_delete($id) and message(-1, lang('delete_failed'));
        admin_audit_log('link_delete', array('link_id' => $id));
        return $id;
    }

    /**
     * 'admin_link' 场景 safe_token 统一校验：admin_bull_token_guard 失败即 message 终止
     * （fail-closed，无布尔返回；token 取 param('safe_token')）。
     * @param int $uid 操作者 uid
     * @return void
     */
    private static function verifyToken(int $uid): void
    {
        $safeToken = param('safe_token');
        admin_bull_token_guard($uid, 'admin_link', $safeToken);
    }

    /**
     * 友链 URL 协议白名单：非空且不以 http:// 或 https:// 开头（大小写不敏感）⇒ 返回 ''。
     * 前缀判定（不校验域名/路径合法性）+ xn_url_strip 去引号/尖括号/控制字符（防第三方主题裸输出进属性逃逸）；
     * 拒绝信号是**空串**，由 create() 报错。
     * @param string $url 已过 admin_param_text 的原始 URL
     * @return string 合法 URL 原样，或 ''（拒绝）
     */
    private static function normalizeUrl(string $url): string
    {
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            return '';
        }
        return xn_url_strip($url);
    }

    /**
     * 解析排序提交的 _POST['data']（原始 POST，不经 param 转义；本场景只取三个 int 字段故安全）：
     * 逐行归一为 {id, oldrank, rank}（缺失/非数值 intval 兜 0），非数组元素跳过。
     * @return array 行数组（结构非法/未提交 ⇒ 空数组）
     */
    private static function sortPayload(): array
    {
        $arr = _POST('data');
        if (!is_array($arr)) return array();

        $rows = array();
        foreach ($arr as $val) {
            if (!is_array($val)) continue;
            $rows[] = array(
                'id' => intval($val['id'] ?? 0),
                'oldrank' => intval($val['oldrank'] ?? 0),
                'rank' => intval($val['rank'] ?? 0),
            );
        }
        return $rows;
    }
}
