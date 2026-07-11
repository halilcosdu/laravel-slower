<?php

namespace HalilCosdu\Slower\Support;

/**
 * Breaks a one-line SQL string into readable lines before top-level keywords.
 *
 * This is intentionally not a real SQL formatter: it only inserts newlines,
 * never reorders or re-cases anything, and it leaves string literals and
 * quoted identifiers untouched so the displayed query is never misleading.
 */
class SqlFormatter
{
    private const KEYWORDS = 'from|where|(?:(?:left|right|inner|outer|cross|full)\s+)*join|group\s+by|having|order\s+by|limit|offset|union(?:\s+all)?|values|set';

    // Never break between a join qualifier ("inner", "left outer", …) and the
    // keyword that follows it.
    private const NOT_AFTER_QUALIFIER = '(?<!\binner)(?<!\bleft)(?<!\bright)(?<!\bouter)(?<!\bcross)(?<!\bfull)';

    public static function format(string $sql): string
    {
        // Split into quoted and unquoted segments; only transform the latter.
        $segments = preg_split(
            '/(\'(?:[^\']|\'\')*\'|"[^"]*"|`[^`]*`)/',
            $sql,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        if ($segments === false) {
            return $sql;
        }

        $formatted = '';

        // PREG_SPLIT_NO_EMPTY guarantees every segment is a non-empty string.
        foreach ($segments as $segment) {
            $isQuoted = in_array($segment[0], ["'", '"', '`'], true);

            $formatted .= $isQuoted
                ? $segment
                : (string) preg_replace('/'.self::NOT_AFTER_QUALIFIER.'\s+(?=(?:'.self::KEYWORDS.')\b)/i', "\n", $segment);
        }

        return $formatted;
    }
}
