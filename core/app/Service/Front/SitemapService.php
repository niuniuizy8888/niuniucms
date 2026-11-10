<?php
namespace App\Service\Front;

class SitemapService
{
    public static function artCount(): int
    {
        return \App\Service\Content\ArtService::sitemapCount();
    }

    /** Counts and rows both exclude categories that a guest cannot read. */
    public static function shardOutOfRange($action, $sub, $conf): bool
    {
        $pagesize = max(1, intval($conf['sitemap_num'] ?? 1000));
        $action = (string)$action;
        if ('' === $action || 'list' === $action || 'xml' === $action) return false;
        if (str_starts_with($action, 'art')) {
            $n = intval($sub);
            return $n < 0 || $n >= max(1, (int)ceil(self::artCount() / $pagesize));
        }
        if (!ctype_digit($action)) return true;
        return intval($action) >= max(1, (int)ceil(\App\Service\Content\VideoService::sitemapCount() / $pagesize));
    }

    /** Public text shards, shard index and XML share one visibility policy. */
    public static function build($action, $conf)
    {
        $action = (string)$action;
        $http = http_url_path();
        $pagesize = max(1, intval($conf['sitemap_num'] ?? 1000));
        if (intval($conf['url_rewrite_on'] ?? 0) > 1) $http = rtrim($http, '/');

        if ('list' === $action) {
            $urls = self::shardUrls($http, $pagesize);
        } elseif ('xml' === $action) {
            return self::buildXml($conf, $http, $pagesize);
        } elseif (self::shardOutOfRange($action, param(2, 0), $conf)) {
            $urls = [];
        } elseif (str_starts_with($action, 'art')) {
            $urls = self::articleUrls(max(0, intval(param(2, 0))) + 1, $pagesize, $http);
        } else {
            $urls = self::videoUrls(max(0, intval($action)) + 1, $pagesize, $conf, $http);
        }
        return ['content_type'=>'text/plain', 'body'=>implode("\r\n", $urls)];
    }

    private static function shardUrls(string $http, int $pagesize): array
    {
        $urls = [];
        $counts = ['sitemap-'=>\App\Service\Content\VideoService::sitemapCount(), 'sitemap-art-'=>self::artCount()];
        foreach ($counts as $prefix => $count) {
            $pages = (int)ceil($count / $pagesize);
            for ($i = 0; $i < $pages; $i++) $urls[] = $http . url_sitemap($prefix . $i);
        }
        return $urls;
    }

    protected static function articleUrls(int $page, int $pagesize, string $http): array
    {
        $urls = [];
        foreach (\App\Service\Content\ArtService::sitemapRows($page, $pagesize) as $art) {
            if (isset($art['url'])) $urls[] = $http . $art['url'];
        }
        return $urls;
    }

    /** Optional tags and internal play links come only from the filtered video rows. */
    protected static function videoUrls(int $page, int $pagesize, $conf, string $http): array
    {
        $withTag = 1 === intval($conf['sitemap_tag'] ?? 0);
        $withPlay = 1 === intval($conf['sitemap_play'] ?? 0);
        $videolist = \App\Service\Content\VideoService::sitemapRows($page, $pagesize, $withTag, $withPlay);
        $urls = [];
        foreach ($videolist as $video) {
            $urls[] = $http . $video['url'];
            if ($withTag) foreach ($video['tag_url'] ?? [] as $tag) $urls[] = $http . $tag['url'];
            if ($withPlay) foreach ($video['play_urls'] ?? [] as $playUrl) $urls[] = $http . $playUrl;
        }
        return array_values(array_unique($urls));
    }

    /** XML includes public articles as well as videos, using the same text shards when needed. */
    protected static function buildXml($conf, string $http, int $pagesize): array
    {
        $esc = static fn($url)=>htmlspecialchars((string)$url, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $count = \App\Service\Content\VideoService::sitemapCount() + self::artCount();
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        if ($count > $pagesize) {
            $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
            foreach (self::shardUrls($http, $pagesize) as $url) $xml .= '<sitemap><loc>' . $esc($url) . "</loc></sitemap>\n";
            $xml .= '</sitemapindex>' . "\n";
        } else {
            $urls = array_merge([rtrim($http, '/') . '/'], self::videoUrls(1, $pagesize, $conf, $http), self::articleUrls(1, $pagesize, $http));
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
            foreach (array_unique($urls) as $url) $xml .= '<url><loc>' . $esc($url) . "</loc></url>\n";
            $xml .= '</urlset>' . "\n";
        }
        return ['content_type'=>'application/xml; charset=utf-8', 'body'=>$xml];
    }
}
