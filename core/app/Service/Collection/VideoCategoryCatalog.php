<?php
namespace App\Service\Collection;

use App\Service\System\CloudOpsService;

/** 分类接口按资源站取数，普通采集接口只作为兼容入口。 */
class VideoCategoryCatalog
{
    public static function sources(): array
    {
        $configured = _SERVER('conf')['api'] ?? [];
        $sources = [];
        foreach (CloudOpsService::cloudPayload()['list'] ?? [] as $item) {
            $code = (string)($item['code'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_.-]{1,32}$/D', $code)
                || (int)($configured[$code]['model'] ?? $item['model'] ?? 0) !== 0) continue;
            $item['api'] = $configured[$code]['api'] ?? $item['api'] ?? '';
            if ($item['api'] !== '') $sources[$code] = $item + ['name'=>$code, 'type'=>''];
        }
        foreach ($configured as $code => $item) {
            if (isset($sources[$code]) || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/D', (string)$code)
                || !isset($item['model']) || (int)$item['model'] !== 0 || empty($item['api'])) continue;
            $sources[$code] = $item + ['code'=>(string)$code, 'name'=>(string)$code, 'type'=>'已配置'];
        }
        return $sources;
    }

    public static function categories(string $api): array
    {
        $url = ApiSourceService::resolveUrl($api);
        $parts = parse_url($url);
        if (empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('资源站采集地址无效，请检查配置');
        }
        $url = explode('#', $url, 2)[0];
        $key = 'video_types_' . substr(hash('sha256', $url), 0, 32);
        $cached = cache_get($key);
        if (!empty($cached['rows'])) return $cached;
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $items = self::readClass($origin . '/api.php/fapi/type/');
        $tree = (bool)array_column($items, 'type_pid');
        if (!$tree) {
            $listUrl = ApiSourceService::withQuery($url, ['ac'=>'list']);
            $items = self::readClass($listUrl);
            if (!$items && $url !== $listUrl) $items = self::readClass($url);
            $tree = $items && count(array_column($items, 'type_pid')) === count($items);
        }
        $result = ['hierarchical'=>$tree, 'rows'=>self::orderedRows($items, $tree)];
        if (!$result['rows']) throw new \RuntimeException('未取到视频分类，请检查资源站后重试。现有分类和绑定已保留。');
        cache_set($key, $result, $tree ? 3600 : 300);
        return $result;
    }

    private static function readClass(string $url): array
    {
        $status = 0;
        $body = https_request($url, '', '', 10, 0, 8 * 1048576, '', $status);
        if ($status !== 200 || !is_string($body)) return [];
        $body = trim($body);
        if (strncmp($body, "\xEF\xBB\xBF", 3) === 0) $body = substr($body, 3);
        $json = json_decode($body, true);
        if (!is_array($json['class'] ?? null)) return [];
        return array_values(array_filter($json['class'], static function ($item) {
            return !is_array($item) || !isset($item['type_mid']) || in_array($item['type_mid'], [1, '1'], true);
        }));
    }

    private static function orderedRows(array $items, bool $tree): array
    {
        $rows = $children = [];
        foreach ($items as $item) {
            if (!is_array($item)) throw new \RuntimeException('源站分类数据无效');
            $id = $item['type_id'] ?? null;
            $pid = $tree ? ($item['type_pid'] ?? null) : 0;
            $name = $item['type_name'] ?? null;
            if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$id)
                || (!is_int($pid) && !is_string($pid)) || !preg_match('/^(0|[1-9][0-9]{0,9})$/D', (string)$pid)
                || !is_string($name) || trim($name) === '' || isset($rows[$id]) || count($rows) >= 1000) {
                throw new \RuntimeException('源站视频分类名称、编号、父级或数量无效，请检查源站分类');
            }
            $rows[$id] = ['type_id'=>(string)$id, 'type_pid'=>(string)$pid, 'type_name'=>trim($name)];
            $children[$pid][] = $id;
        }
        $ordered = [];
        $pending = array_reverse($children[0] ?? []);
        while ($pending) {
            $id = array_pop($pending);
            $ordered[] = $rows[$id];
            foreach (array_reverse($children[$id] ?? []) as $child) $pending[] = $child;
        }
        if (count($ordered) !== count($rows)) throw new \RuntimeException('源站视频分类缺少父级或存在循环，请检查源站分类');
        return $ordered;
    }
}
