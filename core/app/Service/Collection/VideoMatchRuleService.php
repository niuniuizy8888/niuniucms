<?php
namespace App\Service\Collection;

/** 视频采集的查重条件；片名始终参与，播放地址的来源归属由 VideoVersionService 管理。 */
class VideoMatchRuleService
{
    public const FIELDS = array(
        'cid' => '分类', 'year' => '年份', 'douban_id' => '豆瓣 ID', 'sub' => '副标题',
        'area' => '地区', 'lang' => '语言', 'actor' => '主演', 'director' => '导演',
    );

    public static function settings(array $conf): array
    {
        $raw = $conf['collection_match_fields'] ?? 'cid';
        $fields = is_string($raw) ? explode(',', $raw) : array('cid');
        return array(
            'mode' => ($conf['collection_match_mode'] ?? 'auto') === 'custom' ? 'custom' : 'auto',
            'fields' => array_values(array_intersect(array_keys(self::FIELDS), $fields)),
        );
    }

    /** 使用标量字符串保存字段列表，避免运行时配置深合并把已取消的选项补回来。 */
    public static function validate($mode, $fields): array
    {
        if (!in_array($mode, array('auto', 'custom'), true) || !is_array($fields)) {
            throw new \InvalidArgumentException('重复入库规则无效，请重新选择。');
        }
        foreach ($fields as $field) {
            if (!is_string($field) || !isset(self::FIELDS[$field])) {
                throw new \InvalidArgumentException('重复入库条件无效，请重新选择。');
            }
        }
        return array(
            'collection_match_mode' => $mode,
            'collection_match_fields' => implode(',', array_intersect(array_keys(self::FIELDS), $fields)),
        );
    }

    public static function usesCategory(array $rule): bool
    {
        return $rule['mode'] !== 'custom' || in_array('cid', $rule['fields'], true);
    }

    /** 预查重与正式入库使用相同的转义、分隔符及列宽。 */
    public static function sourceMetadata(array $source, int $cid): array
    {
        $row = array('cid' => $cid);
        foreach (self::FIELDS as $field => $_) {
            if ($field === 'cid') continue;
            $value = $source['vod_' . $field] ?? '';
            if ($field === 'year' || $field === 'douban_id') $row[$field] = (int)$value;
            else {
                if ($field === 'actor' || $field === 'director') $value = bull_format_text((string)$value, true);
                $row[$field] = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
            }
        }
        return \App\Service\Content\VideoService::fitColumns($row);
    }

    /** 片名已由候选桶限定。自定义条件全部满足才匹配；来源未提供的条件不参与。 */
    public static function matchCustom(array $candidates, array $incoming, array $rule): ?array
    {
        $match = null;
        foreach ($candidates as $row) {
            foreach ($rule['fields'] as $field) {
                $expected = self::value($incoming[$field] ?? '', $field);
                if ($expected !== '' && $expected !== self::value($row[$field] ?? '', $field)) continue 2;
            }
            // 历史上可能已有多条符合条件的记录；只更新最早一条，不删除其他记录。
            if ($match === null || (int)$row['vid'] < (int)$match['vid']) $match = $row;
        }
        return $match;
    }

    private static function value($value, string $field): string
    {
        if (in_array($field, array('cid', 'year', 'douban_id'), true)) return (int)$value > 0 ? (string)(int)$value : '';
        $text = html_entity_decode((string)$value, ENT_QUOTES, 'UTF-8');
        return preg_replace('/^[\s\p{Z}\x{200B}\x{FEFF}]+|[\s\p{Z}\x{200B}\x{FEFF}]+$/u', '', $text) ?? trim($text);
    }
}
