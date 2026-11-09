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

    /** Empty block/category selection means all readable categories, never all database rows. */
    public static function intersectCategories($requested): array
    {
        $allowed = self::categoryIds();
        if (empty($requested)) return $allowed;
        $requested = array_map('intval', (array)$requested);
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
