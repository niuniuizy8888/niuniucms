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
        if (!$ids) return [];
        $size = max(1, min(100, $size)); $offset = (max(1, $page) - 1) * $size;
        $rows = Schema::sql('SELECT DISTINCT aid FROM ' . Schema::table('tag_art') . ' WHERE tagid IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND aid<>? ORDER BY aid DESC LIMIT ' . $size . ' OFFSET ' . $offset, array_merge($ids, [$exclude]))->fetchAll(\PDO::FETCH_COLUMN);
        return $rows ? (array)ArtService::find($rows, count($rows)) : [];
    }

    public static function mixedList(int $tagid, int $page, int $size): array
    {
        $size = max(1, min(100, $size)); $offset = (max(1, $page) - 1) * $size;
        if (!Schema::ready()) {
            $rows = (array)TagFlowService::videoFind($tagid, $page, $size);
            return $rows ? array_values((array)VideoService::find(array_column($rows, 'vid'), $size, true, true)) : [];
        }
        $rows = Schema::sql('SELECT DISTINCT 0 AS kind,vid AS content_id FROM ' . Schema::table('tag_id') . ' WHERE tagid=? UNION ALL SELECT 1 AS kind,aid AS content_id FROM ' . Schema::table('tag_art') . ' WHERE tagid=? ORDER BY kind,content_id DESC LIMIT ' . $size . ' OFFSET ' . $offset, [$tagid, $tagid])->fetchAll(\PDO::FETCH_ASSOC);
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
        return $result;
    }
}
