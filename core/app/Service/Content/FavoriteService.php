<?php
namespace App\Service\Content;

class FavoriteService
{
    /**
     * 新增一条收藏（favorites 表）。model=1 走文章维度（写 aid、vid 置 NULL），
     * 其余值一律按视频维度处理（写 vid、aid 置 NULL）——两张唯一键 uid_aid / uid_vid 各留一列 NULL，
     * 避免跨类型撞键（审计修复：MySQL 唯一索引不约束 NULL，因此同 uid 的 art 与 vod 收藏可共存）。
     *
     * 唯一键冲突（重复收藏/并发重复）时 PDOException 被 PdoDriver::error() 吞下并记 db_error 日志、底层 insert 返回 0 ⇒ 调用方须以 `$r and ...`
     * 决定是否自增计数列，不能当成成功（MemberService 即如此处理）。写入成功后清 check() 的判定缓存，
     * （清缓存这一步不论 insert 是否拿到 ID 都会执行）；本方法**不碰内容表的 favorites 计数列**（那是 incrementCounter 的职责）。
     *
     * @param int $aid 内容 id（model=1 时按 aid，否则按 vid；内部 intval）
     * @param int $uid 收藏者 uid（内部 intval，不校验登录态——调用方负责 user_login_check）
     * @param int $model 类型标识：1=文章，0=视频
     * @return int|false 新行自增 ID（撞唯一键/失败=0）；空入参 FALSE
     */
    public static function add($aid, $uid, $model)
    {
        // 表结构双唯一键 uid_vid/uid_aid：视频写 vid、文章写 aid，防跨类型撞键（审计修复）
        $data = array(
            'uid' => intval($uid),
            'model' => intval($model),
            'create_date' => time(),
            'vid' => NULL,
            'aid' => NULL,
        );
        if (1 == intval($model)) {
            $data['aid'] = intval($aid);
        } else {
            $data['vid'] = intval($aid);
        }
        $result = \App\Repository\RepositoryFactory::favorite()->insert($data);
        self::clearCheckCache($aid, $uid, $model);
        return $result;
    }

    /** 删除一条收藏：按 uid + model + （model=1 ? aid : vid）组 cond 走 RepositoryFactory::favorite()->delete()，随后清 check() 的判定缓存。不校验记录是否存在，也不回退内容表的 favorites 计数列（减计数由 decrementCounter 负责，调用方须按 check() 结果决定是否减，防重复减）。@param int $aid 内容 id @param int $uid 收藏者 uid @param int $model 1=文章，0=视频 @return int|false 影响行数（0=本无记录，属正常）；空 cond FALSE */
    public static function remove($aid, $uid, $model)
    {
        $cond = array('uid' => intval($uid), 'model' => intval($model));
        if (1 == intval($model)) {
            $cond['aid'] = intval($aid);
        } else {
            $cond['vid'] = intval($aid);
        }
        $result = \App\Repository\RepositoryFactory::favorite()->delete($cond);
        self::clearCheckCache($aid, $uid, $model);
        return $result;
    }

    /** 判定「某用户是否收藏了该内容」：$uid 为空（未登录）直接 false 且不发查询；命中路径先读分布式缓存（键 fav_check_<uid>_<aid>_<model>，TTL 300s，值存 1/0 而非 bool），miss 才 favorite()->count($cond) > 0 并回填 ⇒ 新收藏最多滞后 300s 可见（add/remove 会主动清键，故常规路径不受影响）。@param int $aid 内容 id（model=1 按 aid，否则按 vid） @param int $uid 用户 id @param int $model 1=文章，0=视频 @return bool 缓存里的 1/0 经 == 归一为布尔 */
    public static function check($aid, $uid, $model)
    {
        if (empty($uid)) return false;
        $key = self::checkCacheKey($aid, $uid, $model);
        $result = cache_get($key);
        if (NULL === $result) {
            $cond = array('uid' => intval($uid), 'model' => intval($model));
            if (1 == intval($model)) {
                $cond['aid'] = intval($aid);
            } else {
                $cond['vid'] = intval($aid);
            }
            $result = \App\Repository\RepositoryFactory::favorite()->count($cond) > 0;
            cache_set($key, $result ? 1 : 0, 300);
        }
        return $result == 1;
    }

    /** 内容侧收藏数 +1：model=1 给 art 表做 favorites+ 表达式自增，否则给 vod 表；随后清 'art_<id>'/'vod_<id>' 单行缓存（read() 缓存的是含计数列的整行）。只写计数不回查是否真有收藏记录，调用方须自行保证与 add() 的成功结果配对；无返回值。@param int $aid 内容 id @param int $model 1=文章，0=视频 @return void */
    public static function incrementCounter($aid, $model)
    {
        $aid = intval($aid);
        if (1 == intval($model)) {
            \App\Repository\RepositoryFactory::art()->update(array('aid' => $aid), array('favorites+' => 1));
            cache_delete('art_' . $aid);
            return;
        }
        \App\Repository\RepositoryFactory::vod()->update(array('vid' => $aid), array('favorites+' => 1));
        cache_delete('vod_' . $aid);
    }

    /** 内容侧收藏数 -1：cond 额外带 favorites > 0 钳制（上线前安全审计 F-10，与 LikeService 同口径），并发重复取消时不会把计数减成负数（unsigned 列减到 0 会被条件挡下）。随后清 'art_<id>'/'vod_<id>' 单行缓存；无返回值。@param int $aid 内容 id @param int $model 1=文章，0=视频 @return void */
    public static function decrementCounter($aid, $model)
    {
        $aid = intval($aid);
        // 上线前安全审计修复（F-10）：与 LikeService 同口径 `>0` 钳制，防并发负漂移。
        if (1 == intval($model)) {
            \App\Repository\RepositoryFactory::art()->update(array('aid' => $aid, 'favorites' => array('>' => 0)), array('favorites-' => 1));
            cache_delete('art_' . $aid);
            return;
        }
        \App\Repository\RepositoryFactory::vod()->update(array('vid' => $aid, 'favorites' => array('>' => 0)), array('favorites-' => 1));
        cache_delete('vod_' . $aid);
    }

    /** 统计某用户在某类型下的收藏总数（favorites 表 uid + model 计数，不看内容是否已被删——内容删除后可能留悬空计数）。@param int $uid 用户 id @param int $model 1=文章，0=视频 @return int DbTableRepository::count 声明 : int */
    public static function countByUser($uid, $model)
    {
        return \App\Repository\RepositoryFactory::favorite()->count(array(
            'uid' => intval($uid),
            'model' => intval($model),
        ));
    }

    /** 分页取某用户某类型的收藏明细行（favorites 原表行，id 降序=最新在前，结果以 id 列值为键）。只回收藏表自身，不含标题/图片——内容装配在调用方（前台会员收藏页经 unifiedPull 一类补齐）。@param int $uid 用户 id @param int $page 页码（1 起，内部 intval） @param int $limit 每页条数（内部 intval） @param int $model 1=文章，0=视频 @return array id 为键的收藏行集；无结果为空数组 */
    public static function listByUser($uid, $page, $limit, $model)
    {
        return \App\Repository\RepositoryFactory::favorite()->find(
            array('uid' => intval($uid), 'model' => intval($model)),
            array('id' => -1),
            intval($page),
            intval($limit),
            'id'
        );
    }

    /** check() 的缓存键构造（'fav_check_' . uid . '_' . aid . '_' . model，三段都 intval）——add/remove 清缓存与 check 读取共用同一口径，改格式必须同步两处，否则清了个空键。@param int $aid 内容 id @param int $uid 用户 id @param int $model 类型标识 @return string */
    protected static function checkCacheKey($aid, $uid, $model)
    {
        return 'fav_check_' . intval($uid) . '_' . intval($aid) . '_' . intval($model);
    }

    /** 清掉 check() 的判定缓存（键由 checkCacheKey() 给出），使收藏状态变更在下一次判定即时生效。不判返回值：cache_delete 对不存在的键不报错。@param int $aid 内容 id @param int $uid 用户 id @param int $model 类型标识 @return mixed 透传 cache_delete 的结果（无契约，调用方不应消费） */
    protected static function clearCheckCache($aid, $uid, $model)
    {
        cache_delete(self::checkCacheKey($aid, $uid, $model));
    }
}
