<?php
namespace App\Service\Content;

class TagService
{
    /**
     * 新增标签：转发 tag__create()（→ RepositoryFactory::tag()->insert()）。不清任何缓存（新行不影响既有缓存键）。
     * @param array $data 列名=>值 整行数据（签名已声明）
     * @return int|FALSE 自增 tagid；$data 为空时 FALSE
     */
    public static function create(array $data)
    {
        if (empty($data)) return FALSE;
        return tag__create($data);
    }

    /**
     * 按 name 精确读单个标签行并经 tag_format() 引用装配展示字段。
     * @param string $name 标签名（签名已声明）
     * @return array|null 行数组；未命中或查询失败为 NULL（tag__read→findOne 语义）
     */
    public static function readByName(string $name)
    {
        $row = tag__read(array('name' => $name));
        $row and tag_format($row);
        return $row;
    }

    /**
     * 按 tagid 读单个标签行并经 tag_format() 引用装配展示字段。
     * @param mixed $tagid 标签主键（tag__read 条件绑定值，惯例为 int）
     * @return array|null 行数组；未命中或查询失败为 NULL
     */
    public static function readById($tagid)
    {
        $row = tag__read(array('tagid' => $tagid));
        $row and tag_format($row);
        return $row;
    }

    /**
     * 更新标签并失效 ID 维度缓存 web_tag_{tagid}（TTL 1800s）。
     * $tagid 传数组时按 IN 批量更新、并逐 id 清键；注意 name 维度键 web_tag_{md5(name)} 不清——
     * 改名后旧名缓存仍会命中旧行直到过期（delete() 才双边清）。
     * @param mixed $tagid  标签主键或主键数组
     * @param array $update 列名=>值 更新集（签名已声明）
     * @return int|FALSE 影响行数；入参为空或底层 tag__update 失败为 FALSE
     */
    public static function update($tagid, array $update)
    {
        if (empty($tagid) || empty($update)) return FALSE;
        $result = tag__update(array('tagid' => $tagid), $update);
        if (FALSE === $result) return FALSE;
        if (is_array($tagid)) {
            foreach ($tagid as $_tagid) {
                cache_delete('web_tag_' . $_tagid);
            }
        } else {
            cache_delete('web_tag_' . $tagid);
        }
        return $result;
    }

    /**
     * 标签分页列表（后台管理用）：tag__find 全列查询、按 tagid 排序分页，逐行补顺序号 i 并 tag_format()。
     * @param int  $page     页码（签名已声明）
     * @param int  $pagesize 每页条数（签名已声明）
     * @param bool $desc     true=tagid 降序（新标签在前），false=升序
     * @return array|null 以 tagid 为键的行列表；无结果返回 NULL（非空数组）
     */
    public static function find(int $page, int $pagesize, bool $desc = TRUE)
    {
        $orderby = $desc ? -1 : 1;
        $arrlist = tag__find(array(), array('tagid' => $orderby), $page, $pagesize);
        if (empty($arrlist)) return NULL;
        $i = 0;
        foreach ($arrlist as &$val) {
            ++$i;
            $val['i'] = $i;
            tag_format($val);
        }
        return $arrlist;
    }

    /**
     * 按 tagid 集合分页查标签（条件 array('tagid' => $tagids)：单值等值、数组走 IN），逐行补 i 并 tag_format()。
     * @param mixed $tagids   标签主键或主键数组
     * @param int   $page     页码（签名已声明）
     * @param int   $pagesize 每页条数（签名已声明）
     * @return array|null 以 tagid 为键（tagid 升序）的行列表；无结果返回 NULL
     */
    public static function findByTagIds($tagids, int $page, int $pagesize)
    {
        $arrlist = tag__find(array('tagid' => $tagids), array('tagid' => 1), $page, $pagesize);
        if (empty($arrlist)) return NULL;
        $i = 0;
        foreach ($arrlist as &$val) {
            ++$i;
            $val['i'] = $i;
            tag_format($val);
        }
        return $arrlist;
    }

    /**
     * 标签总数：转发 tag__count()（→ RepositoryFactory::tag()->count()）。
     * @return int 命中行数（转发目标声明 int）
     */
    public static function count()
    {
        return tag__count();
    }

    /**
     * 按标签查关联视频列表：转发 TagFlowService::videoFind()（tag_id 关联表，id 倒序，600s+请求级缓存）。
     * @param mixed $tagid    标签主键
     * @param int   $page     页码
     * @param int   $pagesize 每页条数
     * @return array|null 关联行列表；空结果/查询失败被目标归一为 NULL
     */
    public static function videoFind($tagid, $page, $pagesize)
    {
        return TagFlowService::videoFind($tagid, $page, $pagesize);
    }

    /**
     * 按视频查关联标签：转发 TagFlowService::videoFindByVid()（$vid 支持数组 IN；⚠ 已无缓存——
     * 结果被删除/解绑等**写路径**消费，缓存无法在绑定变更后精确失效，缺陷修复轮已去掉）。
     * 与 videoFind 不同，目标直接透传查询结果、未做空值归一。
     * @param mixed $vid      视频 ID 或 ID 数组
     * @param int   $page     页码
     * @param int   $pagesize 每页条数
     * @return array|false|null 转发目标原样返回（空数组/false/null 均可能出现）
     */
    public static function videoFindByVid($vid, $page, $pagesize)
    {
        return TagFlowService::videoFindByVid($vid, $page, $pagesize);
    }

    /**
     * 删除标签：先按 tagid 验证存在（不存在 FALSE），再删库行，并成对清 web_tag_{tagid} 与
     * web_tag_{md5(name)} 两个维度缓存。
     * @param mixed $tagid 标签主键
     * @return int|FALSE 影响行数；$tagid 为空、标签不存在或底层删除失败为 FALSE
     */
    public static function delete($tagid)
    {
        if (empty($tagid)) return FALSE;
        $read = self::readById($tagid);
        if (empty($read)) return FALSE;
        $result = tag__delete($tagid);
        if (FALSE === $result) return FALSE;
        cache_delete('web_tag_' . $tagid);
        cache_delete('web_tag_' . md5($read['name']));
        return $result;
    }

    /**
     * 带缓存的按 ID 读标签：请求级 static + 分布式缓存 web_tag_{tagid}（TTL 1800s）。
     * 只缓存命中行——未命中不回填缓存（负结果每请求都查库）；写操作方需 cache_delete 对应键。
     * @param mixed $tagid 标签主键
     * @return array|null 标签行；未命中为 NULL
     */
    public static function readByIdCache($tagid)
    {
        $key = 'web_tag_' . $tagid;
        static $cache = array();
        if (isset($cache[$key])) return $cache[$key];
        $row = cache_get($key);
        if (NULL === $row) {
            $row = self::readById($tagid);
            $row and cache_set($key, $row, 1800);
        }
        $cache[$key] = $row ? $row : NULL;
        return $cache[$key];
    }

    }
