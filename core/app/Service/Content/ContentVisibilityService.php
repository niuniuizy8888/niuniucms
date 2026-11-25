<?php
namespace App\Service\Content;

/** Read permissions shared by frontend lists, recommendations and their caches. */
class ContentVisibilityService
{
    public static function categoryIds(): array
    {
        global $catelist, $gid;
        $ids = [];
        foreach ((array)$catelist as $cid => $cate) {
            if ((int)$cid > 0 && CateService::accessUser((int)$cid, (int)$gid, 'allowread')) $ids[] = (int)$cid;
        }
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** Recompute from current permissions, including when an existing page cache could be hit. */
    public static function fingerprint(): string
    {
        return hash('sha256', implode(',', self::categoryIds()));
    }

    /**
     * 求「请求分类」与「当前用户组可读分类」的交集：
     * - 未指定（null / '' / 0 / '0'）→ 全部可读分类；
     * - 显式传入空数组 []（例如受限父栏目展开后无任何可读子栏目）→ 必须返回空数组，禁止 fail-open 回落全站。
     */
    public static function intersectCategories($requested): array
    {
        $allowed = self::categoryIds();
        if ($requested === null || $requested === '' || $requested === 0 || $requested === '0') {
            return $allowed;
        }
        if (is_array($requested)) {
            if (count($requested) === 0) return [];
            $requested = array_map('intval', $requested);
        } else {
            $requested = [intval($requested)];
        }
        return array_values(array_intersect($allowed, $requested));
    }

    public static function filter(array $rows): array
    {
        global $gid;
        foreach ($rows as $key => $row) {
            if (!CateService::accessUser((int)($row['cid'] ?? 0), (int)$gid, 'allowread')) unset($rows[$key]);
        }
        return $rows;
    }
}
