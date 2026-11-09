<?php
namespace App\Service\Collection;

use App\Repository\RepositoryFactory;

/** Classification rules shared by legacy binding and every collection importer. */
class CollectionCategoryService
{
    public static function sourceModel(string $code, array $conf): ?int
    {
        $model = $conf['api'][$code]['model'] ?? null;
        return in_array($model, [0, 1, '0', '1'], true) ? (int)$model : null;
    }

    /** Query current rows, not the category cache; locking reads also avoid stale replicas. */
    public static function targets(?int $model = null, ?array $ids = null): array
    {
        if ($model !== null && !in_array($model, [0, 1], true)) return [];
        if ($ids !== null) {
            $ids = array_values(array_unique(array_filter(array_map([self::class, 'positiveId'], $ids))));
            if (!$ids) return [];
        }
        $query = RepositoryFactory::cate()->builder()->select(['cid', 'name', 'model', 'category'])
            ->whereIn('model', $model === null ? [0, 1] : [$model])->where('category', '<>', 2)->lock();
        if ($ids !== null) $query->whereIn('cid', $ids);
        $rows = $query->get();
        if ($rows === false) throw new \RuntimeException('读取采集分类失败，请稍后重试');
        $out = [];
        foreach ($rows as $row) $out[(int)$row['cid']] = $row;
        return $out;
    }

    public static function validBindings(array $bindings, int $model): array
    {
        $targets = self::targets($model, array_values($bindings));
        $out = [];
        foreach ($bindings as $name => $value) {
            $cid = self::positiveId($value);
            if (isset($targets[$cid])) $out[$name] = $cid;
        }
        return $out;
    }

    private static function positiveId($value): int
    {
        if (!is_int($value) && !is_string($value)) return 0;
        if (!preg_match('/^[1-9][0-9]*$/D', (string)$value)) return 0;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? 0 : $id;
    }
}
