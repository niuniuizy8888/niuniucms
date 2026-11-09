<?php
namespace App\Service\Content;

use App\Service\System\ArticleTagSchema as Schema;

/** Article IDs never enter tag_id; art.tag remains the compatible display snapshot. */
class ArticleTagService
{
    private static $revision = 0;

    public static function revision(): int { return self::$revision; }
    public static function map(string $json): array
    {
        $out = [];
        foreach ((array)json_decode($json, true) as $id => $name) {
            if ((int)$id > 0 && is_string($name) && $name !== '') $out[(int)$id] = $name;
        }
        return $out;
    }

    /** Recreate dictionary entries lost by older deletes, preserving the saved tag IDs. */
    public static function ensureNames(array $map): void
    {
        if (!$map) return;
        $ids = array_keys($map);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $existing = Schema::sql('SELECT tagid FROM ' . Schema::table('tag') . ' WHERE tagid IN (' . $marks . ')', $ids)->fetchAll(\PDO::FETCH_COLUMN);
        foreach (array_chunk(array_diff($ids, array_map('intval', $existing)), 200) as $chunk) {
            $values = $args = [];
            foreach ($chunk as $id) { $values[] = '(?,?,0)'; $args[] = $id; $args[] = $map[$id]; }
            Schema::sql('INSERT IGNORE INTO ' . Schema::table('tag') . ' (tagid,name,`count`) VALUES ' . implode(',', $values), $args);
        }
    }

    /** Migration only: transfer article-owned links and keep every legitimate colliding video link. */
    public static function migrateRows(array $rows): void
    {
        if (!$rows) return;
        $aids = array_column($rows, 'aid');
        $marks = implode(',', array_fill(0, count($aids), '?'));
        $videos = [];
        foreach (Schema::sql('SELECT vid,tag FROM ' . Schema::table('vod') . ' WHERE vid IN (' . $marks . ')', $aids)->fetchAll(\PDO::FETCH_ASSOC) as $video) {
            $videos[(int)$video['vid']] = self::map((string)$video['tag']);
        }
        $names = [];
        foreach ($rows as $row) $names += self::map((string)$row['tag']);
        self::ensureNames($names);
        $values = $args = $remove = [];
        foreach ($rows as $row) {
            $aid = (int)$row['aid'];
            foreach (self::map((string)$row['tag']) as $tid => $name) {
                $values[] = '(?,?)'; $args[] = $tid; $args[] = $aid;
                if (!isset($videos[$aid][$tid])) $remove[$aid][] = $tid;
            }
        }
        if ($values) Schema::sql('INSERT IGNORE INTO ' . Schema::table('tag_art') . ' (tagid,aid) VALUES ' . implode(',', $values), $args);
        // Only the article's own legacy labels may be removed, never unrelated video labels.
        $clauses = $args = [];
        foreach ($remove as $aid => $tids) {
            $clauses[] = '(vid=? AND tagid IN (' . implode(',', array_fill(0, count($tids), '?')) . '))';
            $args[] = $aid; foreach ($tids as $tid) $args[] = $tid;
        }
        if ($clauses) Schema::sql('DELETE FROM ' . Schema::table('tag_id') . ' WHERE ' . implode(' OR ', $clauses), $args);
        self::recount(array_keys($names));
    }

    /** Idempotent totals across both indexed model-specific tables. Zero-count labels stay restorable. */
    public static function recount(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
        if ($ids) ++self::$revision;
        foreach (array_chunk($ids, 200) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $tag = Schema::table('tag'); $vod = Schema::table('tag_id'); $art = Schema::table('tag_art');
            Schema::sql('UPDATE ' . $tag . ' t SET t.`count`=(SELECT COUNT(DISTINCT v.vid) FROM ' . $vod . ' v WHERE v.tagid=t.tagid)+(SELECT COUNT(*) FROM ' . $art . ' a WHERE a.tagid=t.tagid) WHERE t.tagid IN (' . $marks . ')', $chunk);
            foreach ($chunk as $id) cache_delete('web_tag_' . $id);
        }
        if ($ids && function_exists('page_cache_bump')) page_cache_bump(true);
    }

    public static function post(int $aid, int $cid, string $str): string
    {
        Schema::ensureReady();
        if ($aid <= 0) return '';
        $map = [];
        $limit = max(0, (int)(_SERVER('conf')['tag_num'] ?? 10));
        foreach (array_unique(explode(',', $str)) as $name) {
            $name = TagFlowService::normalizeName($name);
            if ($name === '' || count($map) >= $limit) continue;
            $row = tag_read_name($name);
            $id = $row ? (int)$row['tagid'] : (int)tag_create(['name' => $name, 'count' => 0]);
            if ($id <= 0) throw new \RuntimeException('文章标签保存失败，请重试');
            $map[$id] = $name;
        }
        self::sync($aid, $map);
        return $map ? xn_json_encode($map) : '';
    }

    public static function sync(int $aid, array $map): void
    {
        Schema::ensureReady();
        self::ensureNames($map);
        $old = Schema::sql('SELECT tagid FROM ' . Schema::table('tag_art') . ' WHERE aid=?', [$aid])->fetchAll(\PDO::FETCH_COLUMN);
        $ids = array_keys($map);
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            Schema::sql('DELETE FROM ' . Schema::table('tag_art') . ' WHERE aid=? AND tagid NOT IN (' . $marks . ')', array_merge([$aid], $ids));
            $values = $args = [];
            foreach ($ids as $id) { $values[] = '(?,?)'; $args[] = $id; $args[] = $aid; }
            Schema::sql('INSERT IGNORE INTO ' . Schema::table('tag_art') . ' (tagid,aid) VALUES ' . implode(',', $values), $args);
        } else {
            Schema::sql('DELETE FROM ' . Schema::table('tag_art') . ' WHERE aid=?', [$aid]);
        }
        self::recount(array_merge($old, $ids));
    }

    public static function delete(array $aids): void
    {
        Schema::ensureReady();
        foreach (array_chunk($aids, 200) as $chunk) {
            if (!$chunk) continue;
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $ids = Schema::sql('SELECT DISTINCT tagid FROM ' . Schema::table('tag_art') . ' WHERE aid IN (' . $marks . ')', $chunk)->fetchAll(\PDO::FETCH_COLUMN);
            Schema::sql('DELETE FROM ' . Schema::table('tag_art') . ' WHERE aid IN (' . $marks . ')', $chunk);
            self::recount($ids);
        }
    }

    public static function find(array $tagids, int $page, int $size, int $exclude = 0): array
    {
        if (!Schema::ready()) return [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $tagids), static fn($id) => $id > 0)));
        $cids = ContentVisibilityService::categoryIds();
        if (!$ids || !$cids) return [];
        $size = max(1, min(100, $size)); $offset = (max(1, $page) - 1) * $size;
        $rows = Schema::sql('SELECT DISTINCT r.aid FROM ' . Schema::table('tag_art') . ' r JOIN ' . Schema::table('art') . ' c ON c.aid=r.aid WHERE r.tagid IN (' . self::marks($ids) . ') AND c.cid IN (' . self::marks($cids) . ') AND r.aid<>? ORDER BY r.aid DESC LIMIT ' . $size . ' OFFSET ' . $offset, array_merge($ids, $cids, [$exclude]))->fetchAll(\PDO::FETCH_COLUMN);
        return $rows ? ContentVisibilityService::filter((array)ArtService::find($rows, count($rows))) : [];
    }

    public static function mixedList(int $tagid, int $page, int $size): array
    {
        $size = max(1, min(100, $size)); $offset = (max(1, $page) - 1) * $size;
        [$query, $args] = self::visibleBindings([$tagid]);
        if ($query === '') return [];
        $rows = Schema::sql('SELECT DISTINCT kind,content_id FROM (' . $query . ') visible ORDER BY kind,content_id DESC LIMIT ' . $size . ' OFFSET ' . $offset, $args)->fetchAll(\PDO::FETCH_ASSOC);
        $vids = $aids = [];
        foreach ($rows as $row) {
            if ((int)$row['kind'] === 0) $vids[] = (int)$row['content_id'];
            else $aids[] = (int)$row['content_id'];
        }
        $videos = $vids ? (array)VideoService::find($vids, count($vids), true, true) : [];
        $articles = $aids ? (array)ArtService::find($aids, count($aids)) : [];
        $result = [];
        foreach ($rows as $row) {
            $content = (int)$row['kind'] === 0 ? ($videos[$row['content_id']] ?? null) : ($articles[$row['content_id']] ?? null);
            if ($content) $result[] = $content;
        }
        return array_values(ContentVisibilityService::filter($result));
    }

    private static function marks(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    private static function legacyVideoCondition(): string
    {
        // Before migration, an article ID may occupy this relation's vid. Only a video owning
        // the same tag in its saved JSON may be returned. Do not migrate during frontend reads.
        return Schema::ready() ? '' : " AND c.tag REGEXP CONCAT('\"',r.tagid,'\"[[:space:]]*:')";
    }

    /** Filter before pagination/counting, rather than leaving empty pages after removing denied rows. */
    private static function visibleBindings(array $tagids, bool $videoOnly = false): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $tagids), static fn($id) => $id > 0)));
        $cids = ContentVisibilityService::categoryIds();
        if (!$ids || !$cids) return ['', []];
        $where = 'r.tagid IN (' . self::marks($ids) . ') AND c.cid IN (' . self::marks($cids) . ')';
        $query = 'SELECT DISTINCT r.tagid,0 AS kind,r.vid AS content_id FROM ' . Schema::table('tag_id') . ' r JOIN ' . Schema::table('vod') . ' c ON c.vid=r.vid WHERE ' . $where . self::legacyVideoCondition();
        $args = array_merge($ids, $cids);
        if (!$videoOnly && Schema::ready()) {
            $query .= ' UNION ALL SELECT DISTINCT r.tagid,1 AS kind,r.aid AS content_id FROM ' . Schema::table('tag_art') . ' r JOIN ' . Schema::table('art') . ' c ON c.aid=r.aid WHERE ' . $where;
            $args = array_merge($args, $ids, $cids);
        }
        return [$query, $args];
    }

    public static function visibleCount(int $tagid): int
    {
        [$query, $args] = self::visibleBindings([$tagid]);
        return $query === '' ? 0 : (int)Schema::sql('SELECT COUNT(*) FROM (' . $query . ') visible', $args)->fetchColumn();
    }

    public static function relatedVideos(array $tagids, int $limit): array
    {
        [$query, $args] = self::visibleBindings($tagids, true);
        if ($query === '') return [];
        $limit = max(1, min(100, $limit));
        $ids = Schema::sql('SELECT DISTINCT content_id FROM (' . $query . ') visible ORDER BY content_id DESC LIMIT ' . $limit, $args)->fetchAll(\PDO::FETCH_COLUMN);
        return $ids ? ContentVisibilityService::filter((array)VideoService::findAsc($ids, count($ids), true)) : [];
    }

    /** Tag cloud counts and names are scoped to readable content, without changing stored global totals. */
    public static function visibleTags(int $page, int $size): array
    {
        $cids = ContentVisibilityService::categoryIds();
        if (!$cids) return ['count' => 0, 'rows' => []];
        $size = max(1, min(100, $size)); $offset = (max(1, $page) - 1) * $size;
        $where = 'EXISTS (SELECT 1 FROM ' . Schema::table('tag_id') . ' r JOIN ' . Schema::table('vod') . ' c ON c.vid=r.vid WHERE r.tagid=t.tagid AND c.cid IN (' . self::marks($cids) . ')' . self::legacyVideoCondition() . ')';
        $args = $cids;
        if (Schema::ready()) {
            $where .= ' OR EXISTS (SELECT 1 FROM ' . Schema::table('tag_art') . ' r JOIN ' . Schema::table('art') . ' c ON c.aid=r.aid WHERE r.tagid=t.tagid AND c.cid IN (' . self::marks($cids) . '))';
            $args = array_merge($args, $cids);
        }
        $count = (int)Schema::sql('SELECT COUNT(*) FROM ' . Schema::table('tag') . ' t WHERE ' . $where, $args)->fetchColumn();
        $rows = $count ? Schema::sql('SELECT t.tagid,t.name,t.en,t.seo_title,t.seo_key,t.seo_des FROM ' . Schema::table('tag') . ' t WHERE ' . $where . ' ORDER BY t.tagid DESC LIMIT ' . $size . ' OFFSET ' . $offset, $args)->fetchAll(\PDO::FETCH_ASSOC) : [];
        if ($rows) {
            [$query, $countArgs] = self::visibleBindings(array_column($rows, 'tagid'));
            $counts = Schema::sql('SELECT tagid,COUNT(*) AS total FROM (' . $query . ') visible GROUP BY tagid', $countArgs)->fetchAll(\PDO::FETCH_KEY_PAIR);
            foreach ($rows as $i => &$row) {
                $row['count'] = (int)($counts[$row['tagid']] ?? 0);
                $row['i'] = $i + 1;
                tag_format($row);
            }
            unset($row);
        }
        return ['count' => $count, 'rows' => array_column($rows, null, 'tagid')];
    }
}
