<?php
namespace App\Service\Account;

class GroupService
{
    /**
     * 新增用户组行，随后失效 grouplist 列表缓存。
     *
     * @param array $data 列名=>值 的整行数据（含权限位键）
     * @return int|false 自增主键 gid；$data 为空返回 FALSE
     */
    public static function create(array $data)
    {
        if (empty($data)) return FALSE;
        $result = group__create($data);
        self::listCacheDelete();
        return $result;
    }

    /**
     * 按 gid 更新用户组行（转发兼容壳 group__update），随后失效 grouplist 列表缓存。
     *
     * @param mixed $gid  目标组主键
     * @param array $data 列名=>值 更新集
     * @return int|false 影响行数；空条件/空更新返回 FALSE
     */
    public static function update($gid, array $data)
    {
        $result = group__update($gid, $data);
        self::listCacheDelete();
        return $result;
    }

    /**
     * 读单个用户组行（转发兼容壳 group__read）。
     *
     * @param mixed $gid 目标组主键
     * @return array|null 命中行；不存在返回 NULL
     */
    public static function read($gid)
    {
        return group__read($gid);
    }

    /**
     * 删除用户组行（转发兼容壳 group__delete），随后失效 grouplist 列表缓存。
     *
     * @param mixed $gid 目标组主键；空值被拒绝
     * @return int|false 影响行数；$gid 为空返回 FALSE
     */
    public static function delete($gid)
    {
        if (empty($gid)) return FALSE;
        $result = group__delete($gid);
        self::listCacheDelete();
        return $result;
    }

    /**
     * 分页查用户组列表（转发兼容壳 group__find → RepositoryFactory::group()->find，结果以 gid 为键）。
     *
     * @param array $cond     查询条件
     * @param array $orderby  排序（默认按 gid 升序）
     * @param int   $page     页码
     * @param int   $pagesize 每页条数（默认 1000，近似全量）
     * @return array 以 gid 索引的行数组；无结果=空数组
     */
    public static function find(array $cond = array(), array $orderby = array('gid' => 1), int $page = 1, int $pagesize = 1000)
    {
        return group__find($cond, $orderby, $page, $pagesize);
    }

    /**
     * 取用户组名称，读全局 $grouplist 视图（调用前须已装载，如经 group_list_cache()）。
     *
     * @param mixed $gid 组主键
     * @return string 组名；不存在返回空串
     */
    public static function name($gid)
    {
        global $grouplist;
        return isset($grouplist[$gid]['name']) ? $grouplist[$gid]['name'] : '';
    }

    /**
     * 用户组计数（走 RepositoryFactory::group()->count）。
     *
     * @param array $cond 查询条件；空=全表计数
     * @return int
     */
    public static function count(array $cond = array())
    {
        return \App\Repository\RepositoryFactory::group()->count($cond);
    }

    /**
     * 当前用户组最大 gid（走 RepositoryFactory::group()->maxId('gid')，供建组预分配）。
     *
     * @return int 空表返回 0
     */
    public static function maxId()
    {
        return \App\Repository\RepositoryFactory::group()->maxId('gid');
    }

    /**
     * 权限位判定：检查用户组 $gid 是否拥有权限位 $access（如 intoadmin/managevod）。
     *
     * 判定源为全局 $grouplist（调用前须已装载），结果进程内 memo。
     * 语义（后台红线，见 CLAUDE.md「开发约定·后台参数与门禁」）：
     *  · $gid 为空 ⇒ FALSE；
     *  · gid=1（超级管理员组）⇒ 恒 TRUE，不看权限位；
     *  · **fail-closed**：组不存在（删组后成员残留/脏数据）直接 FALSE，绝不放行；
     *  · 其余按 $group[$access] 真值判定，权限位缺失/为 0 均视为无权限。
     * 后台每路由的 group_access($gid,'xxx') 门禁均落到此函数。
     *
     * @param mixed  $gid    用户组主键
     * @param string $access 权限位列名
     * @return bool 有权限为 TRUE
     */
    public static function access($gid, string $access)
    {
        global $grouplist;
        if (empty($gid)) return FALSE;
        static $result = array();
        $k = 'group_' . $gid . '-' . $access;
        if (isset($result[$k])) return $result[$k];
        if (1 == $gid) return TRUE;
        // fail-closed：组不存在（删组后成员残留/数据异常）时不再放行，避免权限判定依赖脏数据
        if (!isset($grouplist[$gid])) return FALSE;
        $group = $grouplist[$gid];
        $result[$k] = empty($group[$access]) ? FALSE : TRUE;
        return $result[$k];
    }

    /**
     * 取全量用户组列表（以 gid 为键），优先读缓存键 grouplist，未命中回源 find() 并回填。
     * index.inc.php 用它初始化全局 $grouplist（权限判定的数据源）。
     *
     * @return array gid=>行 的组列表
     */
    public static function listCache()
    {
        $grouplist = cache_get('grouplist');
        if (NULL === $grouplist || FALSE === $grouplist) {
            $grouplist = self::find();
            cache_set('grouplist', $grouplist);
        }
        return $grouplist;
    }

    /**
     * 失效 grouplist 缓存键（组增删改后必须调用，否则权限视图滞后）。
     *
     * @return mixed 底层 cache_delete 的结果（缓存未装载时可能为 NULL）
     */
    public static function listCacheDelete()
    {
        return cache_delete('grouplist');
    }

    /**
     * 取持久化组列表镜像（兼容壳 group_get 的实现）：
     * 进程内全局 $g_grouplist → website KV 键 grouplist → 回源 find() 并顺手 set() 回写。
     * 注意与 listCache()（缓存层视图）是两条独立存储路径。
     *
     * @return array gid=>行 的组列表
     */
    public static function get()
    {
        global $g_grouplist;
        FALSE === $g_grouplist and $g_grouplist = website_get('grouplist');
        if (empty($g_grouplist)) {
            $g_grouplist = self::find();
            $g_grouplist and self::set($g_grouplist);
        }
        return $g_grouplist;
    }

    /**
     * 写组列表镜像：更新全局 $g_grouplist 并 website_set 持久化到 website KV（兼容壳 group_set 的实现）。
     *
     * @param mixed $val gid=>行 的组列表数组
     * @return mixed 底层 kv_set 的写入结果（成功语义随 KV 层实现）
     */
    public static function set($val)
    {
        global $g_grouplist;
        $g_grouplist = $val;
        return website_set('grouplist', $g_grouplist);
    }
}

