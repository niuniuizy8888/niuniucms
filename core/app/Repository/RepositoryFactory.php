<?php
namespace App\Repository;

class RepositoryFactory
{
    /** @var array<string, DbTableRepository> 仓库实例缓存 */
    protected static $instances = array();

    /**
     * 取（并缓存）某表的通用仓库实例。表名走白名单正则，非法表名返回**空表名**仓库而非抛错，
     * 使后续查询命中零结果（防任意表访问，安全红线）。
     * 实际机制链（勿当成静默零行）：空表名 → qi() 拼出空反引号 → MySQL SQL 1064 语法错误
     * → PdoDriver::prepare_execute 吞异常返回 false 并写 db_error 日志 → QueryBuilder::get() 返回 false
     * → find() 的 `if (!$rows) return array()` 归一为空集。即：**调用方看到空数组，日志里多一条 1064**；
     * 排查「查询恒空但表里明明有数据」时，先看表名是不是被这里的白名单挡了。
     *
     * @param string $table      不含前缀的表名，仅允许 [a-z_][a-z0-9_]*
     * @param string $primaryKey 主键列名
     * @return DbTableRepository 同 (table,primaryKey) 组合进程内复用同一实例
     */
    public static function table(string $table, string $primaryKey): DbTableRepository
    {
        // 表名白名单：仅允许 [a-z_][a-z0-9_]*，防任意表访问（安全红线）
        if (!preg_match('#^[a-z_][a-z0-9_]*$#', $table)) {
            return new DbTableRepository('', $primaryKey);
        }
        $key = $table . ':' . $primaryKey;
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new DbTableRepository($table, $primaryKey);
        }
        return self::$instances[$key];
    }

    /** 分类表仓库（主键 cid）。 */
    public static function cate(): DbTableRepository { return self::table('cate', 'cid'); }
    /** 文章主表仓库（主键 aid）。 */
    public static function art(): DbTableRepository { return self::table('art', 'aid'); }
    /** 文章-分类关联表仓库（主键 aid）。 */
    public static function artAid(): DbTableRepository { return self::table('art_aid', 'aid'); }
    /** 友情链接表仓库（主键 id）。 */
    public static function link(): DbTableRepository { return self::table('link', 'id'); }
    /** 会员用户表仓库（主键 uid）。 */
    public static function user(): DbTableRepository { return self::table('user', 'uid'); }
    /** 用户组表仓库（主键 gid），GroupService 权限判定取数走此。 */
    public static function group(): DbTableRepository { return self::table('group', 'gid'); }
    /** 标签表仓库（主键 tagid）。 */
    public static function tag(): DbTableRepository { return self::table('tag', 'tagid'); }
    /** 标签-视频关联表仓库（库表 tag_id，主键 id）。 */
    public static function tagVideo(): DbTableRepository { return self::table('tag_id', 'id'); }
    /** 视频主表仓库（主键 vid）。 */
    public static function vod(): DbTableRepository { return self::table('vod', 'vid'); }
    /** 视频播放源/剧集表仓库（主键 vid）。 */
    public static function vodVid(): DbTableRepository { return self::table('vod_vid', 'vid'); }
    /** 视频置顶关联表仓库（库表 vod_sticky，主键 vid）。 */
    public static function stickyVod(): DbTableRepository { return self::table('vod_sticky', 'vid'); }
    /** 点赞记录表仓库（主键 id）。 */
    public static function like(): DbTableRepository { return self::table('like', 'id'); }
    /** 收藏记录表仓库（库表 favorites，主键 id）。 */
    public static function favorite(): DbTableRepository { return self::table('favorites', 'id'); }
    /** 弹幕表仓库（主键 id）。 */
    public static function danmu(): DbTableRepository { return self::table('danmu', 'id'); }
    /** 搜索热词表仓库（库表 search_hot，主键 id）。 */
    public static function hotKeyword(): DbTableRepository { return self::table('search_hot', 'id'); }

    /**
     * 内容复合查询仓库（单例，键用类名，与 table 缓存共用 $instances）。
     * 承载 vod/art 列表的多条件组合查询，返回形态见 ContentQueryRepository。
     *
     * @return ContentQueryRepository
     */
    public static function contentQuery(): ContentQueryRepository
    {
        $key = ContentQueryRepository::class;
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new ContentQueryRepository();
        }
        return self::$instances[$key];
    }
}
