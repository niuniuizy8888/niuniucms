<?php
namespace App\Service\Admin;

class HotManageService
{
    /**
     * 后台「搜索热词」列表页数据：assertTableReady 预检表存在（缺失 message 中断）；
     * page 钳 1..10000（VULN-M10 消无界 OFFSET），每页固定 20；keyword 透传给 findPage。
     * 只读视图，不做 token 校验（写操作在 delete/truncate 分支各自门禁）。
     * @param array $filters {page?: 页码原值, keyword?: 搜索词}（来自后台路由取参）
     * @param int $uid 当前管理员 UID（safe_token 场景指纹绑定用）
     * @return array 后台视图数据 {header, keyword, arrlist, pagination(HTML), safe_token}
     */
    public static function searchPage(array $filters, int $uid): array
    {
        self::assertTableReady(1);

        $page = max(1, intval($filters['page'] ?? 1));
        // 上线前审计修复（VULN-M10）：分页上界钳制——消除无界 OFFSET（$offset 直接入 LIMIT）
        $page = min($page, 10000);
        $pagesize = 20;
        $keyword = trim((string)($filters['keyword'] ?? ''));
        $result = self::findPage($keyword, $page, $pagesize);
        $extra = array('page' => '{page}');
        $keyword !== '' and $extra['keyword'] = urlencode($keyword);

        return array(
            'header' => array(
                'title' => '搜索热词',
                'mobile_title' => '搜索热词',
            ),
            'keyword' => $keyword,
            'arrlist' => $result['rows'],
            'pagination' => pagination(url('hot-search', $extra, TRUE), $result['count'], $page, $pagesize),
            'safe_token' => bull_token_set($uid, 'admin_hot'),
        );
    }

    /**
     * 删除单个搜索热词（后台写操作三件套齐全：表预检 + admin_bull_token_guard 场景 admin_hot
     * + admin_param_int 取参；id<=0 走 message(-1) 中断）。删后 KeywordService::invalidateCache()
     * 失效前台热词缓存，并写 hot_delete 审计。
     * @param int $uid 当前管理员 UID（token 校验绑定）
     * @return int 被删热词 id
     */
    public static function delete(int $uid): int
    {
        self::assertTableReady(-1);
        self::verifyToken($uid);

        $id = admin_param_int('id');
        $id <= 0 and message(-1, lang('parameter_error'));

        \App\Repository\RepositoryFactory::hotKeyword()->delete(array('id' => $id));
        \App\Service\Content\KeywordService::invalidateCache();
        admin_audit_log('hot_delete', array('hot_id' => $id));
        return $id;
    }

    /**
     * 清空搜索热词表（表预检 + admin_hot 场景 CSRF 闸后 Repository truncate，
     * 不可逆操作，成功写 hot_truncate 审计并失效热词缓存）。
     * @param int $uid 当前管理员 UID
     * @return void
     */
    public static function truncate(int $uid): void
    {
        self::assertTableReady(-1);
        self::verifyToken($uid);

        \App\Repository\RepositoryFactory::hotKeyword()->truncate();
        \App\Service\Content\KeywordService::invalidateCache();
        admin_audit_log('hot_truncate', array('action' => 'truncate'));
    }

    /**
     * 热词分页查询双路径：$keyword 非空走驱动层 prepare_execute——LIKE '%词%'（like_escape 转义，
     * VULN-L01 先转义转义符；前导通配必然全表扫，属已知口径 §三.2），LIMIT 由 intval 钳制后拼入；
     * 空词走 RepositoryFactory::hotKeyword() 的 count/find。
     * ⚠ 两分支 rows 键形态不同：关键字分支为顺序列表，空词分支 find 传了 $key='id' 以 id 为键。
     * @param string $keyword 搜索词（已 trim；'' = 全量列表）
     * @param int $page 页码（>=1，调用方已钳上界）
     * @param int $pagesize 每页条数
     * @return array {count: int 总条数, rows: array 行数据}
     */
    private static function findPage(string $keyword, int $page, int $pagesize): array
    {
        $db = self::db();
        if ($keyword !== '') {
            // 上线前安全审计修复（F-19）：LIKE 通配符转义，对齐 ContentQueryRepository::normalizeKeyword
            // 口径——值本就绑定无注入，但管理员的 %/_ 搜索词此前会被当通配符（搜 100% 匹配 100 任意后缀）。
            // 修复（VULN-L01）：先转义转义符本身（原实现只转义通配符）
            $kwEscaped = function_exists('like_escape') ? like_escape($keyword) : str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $keyword);
            $kwLike = '%' . $kwEscaped . '%';
            $stmtCount = $db->prepare_execute(
                'SELECT COUNT(*) AS n FROM `' . self::tableName() . '` WHERE `keyword` LIKE ?',
                array($kwLike)
            );
            $countRow = $stmtCount ? $stmtCount->fetch() : array('n' => 0);
            $count = intval($countRow['n'] ?? 0);
            $offset = ($page - 1) * $pagesize;
            $stmt = $db->prepare_execute(
                'SELECT * FROM `' . self::tableName() . '` WHERE `keyword` LIKE ? ORDER BY `count` DESC, `update_time` DESC, `id` DESC LIMIT ' . intval($offset) . ',' . intval($pagesize),
                array($kwLike)
            );
            $rows = $stmt ? $stmt->fetchAll() : array();
            return array('count' => $count, 'rows' => $rows);
        }

        return array(
            'count' => \App\Repository\RepositoryFactory::hotKeyword()->count(),
            'rows' => \App\Repository\RepositoryFactory::hotKeyword()->find(array(), array('count' => -1, 'update_time' => -1, 'id' => -1), $page, $pagesize, 'id'),
        );
    }

    /**
     * CSRF/会话闸：param('safe_token') 过 admin_bull_token_guard（场景 admin_hot，绑定 uid）——
     * 校验失败由 guard 内部 message 中断（fail-closed），本函数无返回。
     * @param int $uid 当前管理员 UID
     * @return void
     */
    private static function verifyToken(int $uid): void
    {
        $safeToken = param('safe_token');
        admin_bull_token_guard($uid, 'admin_hot', $safeToken);
    }

    /**
     * 表存在预检：db_find_table（全名）不存在即 message($code, tableHint()) 中断（fail-closed，
     * 未建表的站点不会把 SQL 错误裸露出去）。现行调用：searchPage 传 1，delete/truncate 传 -1。
     * @param int $code 表缺失时 message 的错误码（读页传 1，写操作传 -1）
     * @return void
     */
    private static function assertTableReady(int $code): void
    {
        db_find_table(self::tableName()) || message($code, self::tableHint());
    }

    /** @return string 热词表完整表名（tablepre + 'search_hot'，供 SQL/存在性探测直用） */
    private static function tableName(): string
    {
        return self::db()->tablepre . 'search_hot';
    }

    /** @return string 表缺失时的用户提示文案（指向 install.sql/手工建表两种补救路径） */
    private static function tableHint(): string
    {
        return '搜索热词表不存在，请确认新装使用 install/install.sql（已集成 search_hot 表）或手动建表。';
    }

    /**
     * 取容器注入的数据库驱动句柄（§二.5：读走 _SERVER('db') 门面，优先容器再退超全局镜像）。
     * @return PdoDriver|ProxyDriver|null db_new 产物；未配置数据库时 null（tableName 会随之报错，属站点未安装形态）
     */
    private static function db()
    {
        return _SERVER('db');
    }
}
