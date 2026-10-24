<?php
namespace App\Service\Content;

class LikeService
{
    /**
     * 新增一条点赞记录（不自动递增内容 likes 计数，由调用方配合 incrementCounter()，见 MemberService::toggleLike）。
     * 表有 uid_vid/uid_aid 双唯一键：model=1（文章）写 aid 列、否则（视频）写 vid 列，防跨类型撞键；
     * 并发重复插入撞键时返回失败，调用方据此跳过计数。
     * @param mixed $aid   内容 ID（model=1 为 aid，否则 vid，内部 intval）
     * @param mixed $uid   点赞用户 ID（内部 intval）
     * @param mixed $model 内容模型：1=文章，其余=视频（内部 intval）
     * @return int|FALSE 自增 id；组装的行恒非空，正常返回 int（>0=新插入，撞唯一键时为底层失败值）
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
        $result = \App\Repository\RepositoryFactory::like()->insert($data);
        self::clearCheckCache($aid, $uid, $model);
        return $result;
    }

    /**
     * 删除点赞记录（按 uid+model+aid/vid 定位；不减内容 likes 计数、不做存在性预检，
     * 幂等取消请调用方先 check() 再 remove()+decrementCounter() 组合，见 MemberService::toggleLike）。
     * @param mixed $aid   内容 ID（model=1 对应 aid 列，否则 vid 列）
     * @param mixed $uid   用户 ID
     * @param mixed $model 内容模型：1=文章，其余=视频
     * @return int|FALSE 影响行数（无记录时 0）
     */
    public static function remove($aid, $uid, $model)
    {
        $cond = array('uid' => intval($uid), 'model' => intval($model));
        if (1 == intval($model)) {
            $cond['aid'] = intval($aid);
        } else {
            $cond['vid'] = intval($aid);
        }
        $result = \App\Repository\RepositoryFactory::like()->delete($cond);
        self::clearCheckCache($aid, $uid, $model);
        return $result;
    }

    /**
     * 查询用户是否已点赞：like_check_{uid}_{aid}_{model} 缓存 300s，未命中按 COUNT>0 回填（缓存值存 1/0）。
     * @param mixed $aid   内容 ID
     * @param mixed $uid   用户 ID；空（游客）直接 false，不查库
     * @param mixed $model 内容模型：1=文章，其余=视频
     * @return bool 是否已点赞。注意 300s 缓存窗口内 add/remove 后结果可能滞后（两侧均主动清键收敛）
     */
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
            $result = \App\Repository\RepositoryFactory::like()->count($cond) > 0;
            cache_set($key, $result ? 1 : 0, 300);
        }
        return $result == 1;
    }

    /**
     * 内容点赞数 +1：走 QueryBuilder 原子自增表达式（'likes+' => 1）更新 art/vod 主表（model=1 更 art，否则 vod），
     * 并清对应内容整行缓存（art_{aid} / vod_{aid}）。
     * @param mixed $aid   内容 ID（内部 intval）
     * @param mixed $model 内容模型：1=文章，其余=视频
     * @return void 不回传更新结果
     */
    public static function incrementCounter($aid, $model)
    {
        $aid = intval($aid);
        if (1 == intval($model)) {
            \App\Repository\RepositoryFactory::art()->update(array('aid' => $aid), array('likes+' => 1));
            content_cache_delete('art_', $aid);
            return;
        }
        \App\Repository\RepositoryFactory::vod()->update(array('vid' => $aid), array('likes+' => 1));
        content_cache_delete('vod_', $aid);
    }

    /**
     * 内容点赞数 -1：带 `likes > 0` 条件钳制（审计 F-10——unlike 链路 check(缓存)→remove→decrement 非原子，
     * 并发双 unlike 时第二次删 0 行但照减会致计数负漂移；条件走 QueryBuilder 预编译操作符数组）。
     * model=1 更 art，否则更 vod，并清对应内容整行缓存。
     * @param mixed $aid   内容 ID（内部 intval）
     * @param mixed $model 内容模型：1=文章，其余=视频
     * @return void 不回传更新结果
     */
    public static function decrementCounter($aid, $model)
    {
        $aid = intval($aid);
        // 上线前安全审计修复（F-10）：减计加 `>0` 钳制——unlike 分支「先 check（300s 缓存）
        // 后 remove 再 decrement」非原子，并发双 unlike 或缓存未失效时第二次删 0 行但照减，计数可负漂移。
        // 条件走 applyCond 操作符数组（QueryBuilder 预编译路径）。
        if (1 == intval($model)) {
            \App\Repository\RepositoryFactory::art()->update(array('aid' => $aid, 'likes' => array('>' => 0)), array('likes-' => 1));
            content_cache_delete('art_', $aid);
            return;
        }
        \App\Repository\RepositoryFactory::vod()->update(array('vid' => $aid, 'likes' => array('>' => 0)), array('likes-' => 1));
        content_cache_delete('vod_', $aid);
    }

    /**
     * 统计用户点赞总数：按 uid+model 查 like 表 COUNT。
     * @param mixed $uid   用户 ID（内部 intval）
     * @param mixed $model 内容模型：1=文章，其余=视频（内部 intval）
     * @return int 条数（转发目标 RepositoryFactory::like()->count() 声明 int）
     */
    public static function countByUser($uid, $model)
    {
        return \App\Repository\RepositoryFactory::like()->count(array(
            'uid' => intval($uid),
            'model' => intval($model),
        ));
    }

    /**
     * 用户点赞记录分页列表：按 uid+model 查 like 表，id 倒序（新记录在前），全列查询。
     * @param mixed $uid   用户 ID（内部 intval）
     * @param mixed $page  页码（内部 intval）
     * @param mixed $limit 每页条数（内部 intval）
     * @param mixed $model 内容模型：1=文章，其余=视频（内部 intval）
     * @return array 以记录主键 id 为键的行列表；无结果为空数组
     */
    public static function listByUser($uid, $page, $limit, $model)
    {
        return \App\Repository\RepositoryFactory::like()->find(
            array('uid' => intval($uid), 'model' => intval($model)),
            array('id' => -1),
            intval($page),
            intval($limit),
            'id'
        );
    }

    /**
     * 拼"是否已赞"缓存键：like_check_{uid}_{aid}_{model}，三段均 intval 归一（防同值异型产生多键）。
     * @param mixed $aid   内容 ID
     * @param mixed $uid   用户 ID
     * @param mixed $model 内容模型
     * @return string 缓存键
     */
    protected static function checkCacheKey($aid, $uid, $model)
    {
        return 'like_check_' . intval($uid) . '_' . intval($aid) . '_' . intval($model);
    }

    /**
     * 失效"是否已赞"缓存键（add/remove 后调用，收敛 check() 的 300s 滞后窗口）。
     * @param mixed $aid   内容 ID
     * @param mixed $uid   用户 ID
     * @param mixed $model 内容模型
     * @return void
     */
    protected static function clearCheckCache($aid, $uid, $model)
    {
        cache_delete(self::checkCacheKey($aid, $uid, $model));
    }
}
