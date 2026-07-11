<?php

namespace HalilCosdu\Slower\Support;

/**
 * Produces a stable fingerprint for a captured query so that repeated
 * executions of the same statement can be grouped, regardless of literal
 * values, formatting, placeholder style or IN-list size.
 *
 * This is deliberately a small, regex-based normalizer — not a SQL parser.
 * The tradeoff is documented: a missed normalization yields an extra group
 * (cosmetic), and the golden-fixture test suite guards against the opposite
 * failure mode (distinct queries collapsing into one group).
 *
 * Fingerprints are computed from the *parameterized* SQL (`$record->sql`),
 * never from raw SQL with substituted bindings. The algorithm is versioned:
 * bump VERSION whenever normalization changes so existing fingerprints can
 * be told apart and re-generated (see the `slower:fingerprint` command).
 */
class SqlFingerprinter
{
    public const VERSION = 1;

    public function fingerprint(string $sql): string
    {
        return sha1($this->normalize($sql));
    }

    public function normalize(string $sql): string
    {
        // 1. String literals first (their content must never influence later
        //    passes). Handles '' and \' escapes. Double-quoted and backtick
        //    tokens are identifiers on pgsql/sqlite/mysql and are left alone.
        $sql = (string) preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", '?', $sql);

        // 2. Comments: /* ... */ blocks and -- to end of line.
        $sql = (string) preg_replace('~/\*.*?\*/~s', ' ', $sql);
        $sql = (string) preg_replace('/--[^\n]*/', ' ', $sql);

        // 3. Named placeholders (:id) become positional. The lookbehind keeps
        //    pgsql casts (::text) intact.
        $sql = (string) preg_replace('/(?<!:):\w+/', '?', $sql);

        // 4. Numeric literals. The lookarounds keep identifiers (col_2,
        //    `2fa`, t.col3) untouched.
        $sql = (string) preg_replace('/(?<![a-zA-Z0-9_`".])-?\d+(?:\.\d+)?(?![a-zA-Z0-9_])/', '?', $sql);

        $sql = strtolower($sql);

        // 5. Whitespace, then IN-list collapse (any number of ?s → one).
        $sql = trim((string) preg_replace('/\s+/', ' ', $sql));

        return (string) preg_replace('/\bin\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/', 'in (?)', $sql);
    }
}
