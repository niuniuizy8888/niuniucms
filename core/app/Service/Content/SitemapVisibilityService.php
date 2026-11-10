<?php
namespace App\Service\Content;

/** Search engines always receive the same guest-readable sitemap, even after an admin visit. */
class SitemapVisibilityService
{
    public static function categoryIds(): array
    {
        global $catelist;
        $guest = \App\Service\Account\UserService::guest();
        $ids = [];
        foreach ((array)$catelist as $cid => $cate) {
            if ((int)$cid > 0 && CateService::accessUser((int)$cid, (int)$guest['gid'], 'allowread')) $ids[] = (int)$cid;
        }
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    public static function fingerprint(): string
    {
        return hash('sha256', implode(',', self::categoryIds()));
    }
}
