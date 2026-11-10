<?php
namespace App\Repository;

/** Member-owned relations joined to readable, existing content before counting and paging. */
class MemberRelationRepository
{
    public static function page(string $kind, int $uid, int $model, int $page, int $limit, array $categoryIds): array
    {
        if (!in_array($kind, ['like', 'favorite'], true)) {
            throw new \InvalidArgumentException('Unknown member relation');
        }
        $model = $model === 1 ? 1 : 0;
        $limit = max(1, min(100, $limit));
        $page = max(1, $page);
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds), static fn($id) => $id > 0)));
        $empty = ['ids' => [], 'total' => 0, 'page' => 1, 'limit' => $limit];
        if ($uid <= 0 || !$categoryIds) return $empty;

        $db = _SERVER('db');
        $relation = $db->qi($db->tablepre . ($kind === 'like' ? 'like' : 'favorites'));
        $content = $db->qi($db->tablepre . ($model === 1 ? 'art' : 'vod'));
        $id = $model === 1 ? 'aid' : 'vid';
        $from = ' FROM ' . $relation . ' r INNER JOIN ' . $content . ' c ON c.' . $id . '=r.' . $id
            . ' WHERE r.uid=? AND r.model=? AND c.cid IN (' . implode(',', array_fill(0, count($categoryIds), '?')) . ')';
        $args = array_merge([$uid, $model], $categoryIds);
        $count = $db->prepare_execute('SELECT COUNT(*)' . $from, $args);
        if (!$count) return $empty;
        $total = (int)$count->fetchColumn();
        if ($total === 0) return $empty;
        $page = min($page, (int)ceil($total / $limit));
        $offset = ($page - 1) * $limit;
        $rows = $db->prepare_execute('SELECT r.' . $id . $from . ' ORDER BY r.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, $args);
        return [
            'ids' => $rows ? array_map('intval', $rows->fetchAll(\PDO::FETCH_COLUMN)) : [],
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }
}
