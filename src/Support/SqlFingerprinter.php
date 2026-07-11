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
        // 1. Strip string literals AND comments in a single left-to-right pass.
        //    Whichever token opens first at a given position wins — exactly how
        //    a SQL lexer scans — so a quote inside a comment can't start a
        //    bogus string, and a `--`/`/*` inside a string isn't a comment.
        //    String literals use standard `''` doubling (correct for
        //    pgsql/sqlite and MySQL in ANSI mode); a trailing backslash is an
        //    ordinary character, so the closing quote always terminates the
        //    literal instead of over-running into the following SQL. The
        //    deliberate tradeoff: a MySQL-default `\'` mid-string escape is
        //    read as a terminator, so two shapes that differ only inside such
        //    an inlined literal may share a group. This is rare — fingerprints
        //    are built from the parameterized SQL, where values are `?` — and
        //    a text-only normalizer cannot resolve `\'` without the driver's
        //    escaping mode. A false split (an extra group) is preferred over a
        //    false merge, so we favor the standard-conforming reading.
        //    Double-quoted / backtick tokens are identifiers and are left alone.
        $sql = (string) preg_replace_callback(
            "~'(?:[^']|'')*'|--[^\n]*|/\\*.*?\\*/~s",
            static fn (array $m): string => $m[0][0] === "'" ? '?' : ' ',
            $sql
        );

        // 2. Named placeholders (:id) become positional. The lookbehind keeps
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
