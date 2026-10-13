<?php
/**
 * ProxyDriver — 继承 PdoDriver，重写 connect() 使用连接池
 * DSN 构建支持 MySQL / PostgreSQL / SQLite
 */
class ProxyDriver extends PdoDriver
{
    /** @var ConnectionPool|null */
    private $pool = null;

    public function __construct($conf)
    {
        parent::__construct($conf);
        $this->pool = new ConnectionPool($conf);
    }

    /**
     * 取写连接（重写 PdoDriver::connect()）：改由 ConnectionPool 出栈/建连，不再自己 new PDO。
     * 已持有 wlink 直接复用（每请求单例语义）；池拿不到连接（主库熔断开路）返回 NULL 由上层降级。
     * ⚠ 池建连选项已与父类统一（缺陷修复轮 G）：ConnectionPool::createConnectionFor 同走
     *   PdoDriver::connOptions() 单一事实源 ⇒ FOUND_ROWS / SQLite STRINGIFY_FETCHES=false
     *   等选项与主连接一致，proxy 模式下 rowCount 语义不再分叉。
     * @return PDO|null 写连接句柄；失败 NULL（父类失败会 error() 留痕，本实现静默回 null）
     */
    public function connect(): ?PDO
    {
        if ($this->wlink) return $this->wlink;
        $link = $this->pool->getWrite();
        if ($link) {
            $this->wlink = $link;
        }
        return $link;
    }

    /**
     * 取读连接（重写 PdoDriver::getReadLink()）：配了 slaves 就向池借一条并**缓存进 rlink**，
     * close() 据此经 put() 归还。⚠ 历史缺陷（审查修复）：旧实现借出后既不缓存也不归还 ⇒
     * 读连接从不回到池的空闲栈（读池化形同虚设、每条只读查询新建 TCP），且池的进程静态
     * `pdoNode` 映射只增不减。语义与父类一致：同一请求内读操作粘在一条从库上，从库轮询/
     * 负载均衡在**请求粒度**生效；池取不到（从库全挂/未配）回落父类逻辑（父类自行建从库/降级主库）。
     * @return PDO|null 读连接句柄；失败 NULL
     */
    protected function getReadLink(): ?PDO
    {
        if ($this->rlink) return $this->rlink;
        $slaves = $this->conf['slaves'] ?? [];
        if (!empty($slaves)) {
            $link = $this->pool->getRead();
            if ($link) {
                $this->rlink = $link;
                return $link;
            }
        }
        return parent::getReadLink();
    }

    /**
     * 归还连接而非断开：写/读句柄各自 put() 回 ConnectionPool（按节点键入桶，超上限则丢弃由 GC 释放），
     * 随后把属性置 null。持久化语义由池承担，不是物理关闭 TCP。
     * ⚠ 同句柄守卫必须在置 null **之前**判定（审查修复）：旧实现先 `$this->wlink = null` 再比
     * `$this->rlink !== $this->wlink` ⇒ 比较对象恒为 null，读写同源时同一个 PDO 会被塞进
     * 读/写两个桶（下次借出可能拿到方向不符的连接）。现先把两者取出来再置空。
     * 调用点：析构 __destruct（每请求收尾）。父类 close() 只是置 null，本实现补了归还。
     * @return bool 恒 true（兼容旧 db 接口签名，无失败路径）
     */
    public function close(): bool
    {
        $w = $this->wlink;
        $r = $this->rlink;
        $this->wlink = null;
        $this->rlink = null;
        if ($w) {
            $this->pool->put($w, true);
        }
        if ($r && $r !== $w) {
            $this->pool->put($r, false);
        }
        return true;
    }

    public function __destruct()
    {
        $this->close();
    }
}
