<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Hdhomerun;

/**
 * Field lookups on the space-separated "key=value" strings devices return for
 * variables like /tunerN/status, e.g. "ch=auto:33 lock=8vsb ss=85 snq=90 seq=100".
 */
class StatusString
{
    public static function value(string $status, string $key): ?string
    {
        return preg_match('/(?:^|\s)' . preg_quote($key, '/') . '=(\S+)/', $status, $match) ? $match[1] : null;
    }

    /**
     * Leading unsigned integer of a field, 0 when missing (libhdhomerun's behavior).
     */
    public static function int(string $status, string $key): int
    {
        return preg_match('/(?:^|\s)' . preg_quote($key, '/') . '=(\d+)/', $status, $match) ? (int) $match[1] : 0;
    }

    /**
     * Decibel reading some firmware appends in parentheses, e.g. "ss=85(-45dBm)".
     */
    public static function decibels(string $status, string $key): ?float
    {
        return preg_match('/(?:^|\s)' . preg_quote($key, '/') . '=\d*\((-?\d+(?:\.\d+)?)/', $status, $match)
            ? (float) $match[1]
            : null;
    }
}
