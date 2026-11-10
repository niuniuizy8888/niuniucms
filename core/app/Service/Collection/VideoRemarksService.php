<?php
namespace App\Service\Collection;

/** 只比较含义明确的当前进度；无法比较的备注仍按原采集规则更新。 */
class VideoRemarksService
{
    public static function isRegression(string $current, string $incoming): bool
    {
        if ($current === $incoming || $current === '' || $incoming === '') return false;
        $old = self::progress($current);
        if ($old === null) return false;
        $new = self::progress($incoming);
        if ($new === null || $old['kind'] !== $new['kind'] || $old['season'] !== $new['season']) return false;
        return $new['value'] < $old['value']
            || ($new['value'] === $old['value'] && $old['complete'] && !$new['complete']);
    }

    private static function progress(string $remarks): ?array
    {
        // Normalize only for comparison; the caller retains its original escaping and storage rules.
        $text = html_entity_decode($remarks, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_convert_kana($text, 'n', 'UTF-8');
        if (preg_match('/[0-9][\s\p{Z}]+[0-9]/u', $text)) return null; // Do not fuse separate numbers.
        $text = preg_replace('/[\s\p{Z}]+/u', '', $text);
        if ($text === null || $text === '' || strlen($text) > 1000) return null;
        $season = '';
        if (preg_match('/^第([0-9]+|[零〇一二三四五六七八九十百两]+)季/u', $text, $match)) {
            $season = ctype_digit($match[1]) ? (ltrim($match[1], '0') ?: '0') : $match[1];
            $text = substr($text, strlen($match[0]));
        }

        // Eight-digit variety-show dates must never be compared with episode/issue numbers.
        if (preg_match('/^(?:更新至|更新到|更新)?((?:19|20|21)[0-9]{2})([0-9]{2})([0-9]{2})(?:期)?$/D', $text, $match)
            || preg_match('/^(?:更新至|更新到|更新)?((?:19|20|21)[0-9]{2})[-.\/]([0-9]{1,2})[-.\/]([0-9]{1,2})(?:期)?$/D', $text, $match)) {
            $year = (int)$match[1]; $month = (int)$match[2]; $day = (int)$match[3];
            if (!checkdate($month, $day, $year)) return null;
            return ['kind' => 'date', 'value' => $year * 10000 + $month * 100 + $day, 'season' => $season, 'complete' => false];
        }

        $complete = false;
        if (preg_match('/^(?:更新至|更新到|更新)?(?:第)?([0-9]{1,5})(集|话|期)(完结|已完结|全)?$/uD', $text, $match)) {
            $complete = !empty($match[3]);
        } elseif (preg_match('/^全([0-9]{1,5})(集|话|期)$/uD', $text, $match)) {
            $complete = true;
        } else {
            // Totals (共24集), trailers, cuts, fractions and arbitrary text are not current progress.
            return null;
        }
        return ['kind' => $match[2], 'value' => (int)$match[1], 'season' => $season, 'complete' => $complete];
    }
}
