<?php

use HalilCosdu\Slower\Support\SqlFingerprinter;

/**
 * Golden fixtures: queries that differ only in literal values, whitespace,
 * keyword case, placeholder style, IN-list size or comments MUST share a
 * fingerprint; queries with a different shape MUST NOT collide.
 */
describe('SqlFingerprinter', function () {
    beforeEach(function () {
        $this->fingerprinter = new SqlFingerprinter;
    });

    it('is versioned and produces a 40-char sha1 hex fingerprint', function () {
        expect(SqlFingerprinter::VERSION)->toBe(1)
            ->and($this->fingerprinter->fingerprint('select * from users'))->toMatch('/^[0-9a-f]{40}$/');
    });

    it('groups the same query regardless of literal values', function (string $a, string $b) {
        expect($this->fingerprinter->fingerprint($a))->toBe($this->fingerprinter->fingerprint($b));
    })->with([
        'numeric literals' => [
            'select * from users where id = 1',
            'select * from users where id = 42',
        ],
        'string literals' => [
            "select * from users where email = 'a@example.com'",
            "select * from users where email = 'someone-else@example.com'",
        ],
        'string literal with escaped quote' => [
            "select * from users where name = 'O''Brien'",
            "select * from users where name = 'plain'",
        ],
        'limit and offset values' => [
            'select * from orders order by id limit 10 offset 20',
            'select * from orders order by id limit 25 offset 0',
        ],
        'negative and decimal numbers' => [
            'select * from readings where value > -1.5',
            'select * from readings where value > 3',
        ],
    ]);

    it('groups the same query regardless of formatting', function (string $a, string $b) {
        expect($this->fingerprinter->fingerprint($a))->toBe($this->fingerprinter->fingerprint($b));
    })->with([
        'whitespace and newlines' => [
            "select *\n  from users\n where id = ?",
            'select * from users where id = ?',
        ],
        'keyword case' => [
            'SELECT * FROM users WHERE id = ?',
            'select * from users where id = ?',
        ],
        'inline block comments' => [
            'select /* tenant:3 */ * from users where id = ?',
            'select * from users where id = ?',
        ],
        'trailing line comments' => [
            "select * from users where id = ? -- lookup\n",
            'select * from users where id = ?',
        ],
    ]);

    it('collapses IN lists of any size', function (string $a, string $b) {
        expect($this->fingerprinter->fingerprint($a))->toBe($this->fingerprinter->fingerprint($b));
    })->with([
        'placeholder lists' => [
            'select * from users where id in (?, ?, ?, ?, ?)',
            'select * from users where id in (?)',
        ],
        'literal lists' => [
            'select * from users where id in (1, 2, 3)',
            'select * from users where id in (9)',
        ],
    ]);

    it('normalizes named placeholders to positional ones', function () {
        expect($this->fingerprinter->fingerprint('select * from users where id = :id'))
            ->toBe($this->fingerprinter->fingerprint('select * from users where id = ?'));
    });

    it('keeps genuinely different queries apart', function (string $a, string $b) {
        expect($this->fingerprinter->fingerprint($a))->not->toBe($this->fingerprinter->fingerprint($b));
    })->with([
        'different table' => [
            'select * from users where id = ?',
            'select * from orders where id = ?',
        ],
        'different where column' => [
            'select * from users where id = ?',
            'select * from users where email = ?',
        ],
        'numeric suffix in identifiers matters' => [
            'select * from stats where col_2 = ?',
            'select * from stats where col_3 = ?',
        ],
        'added order by' => [
            'select * from users where id = ?',
            'select * from users where id = ? order by id',
        ],
        'select list shape' => [
            'select id from users where id = ?',
            'select count(*) as aggregate from users where id = ?',
        ],
        'join presence' => [
            'select * from orders',
            'select * from orders inner join users on users.id = orders.user_id',
        ],
    ]);

    it('does not touch double-quoted or backtick-quoted identifiers', function () {
        // "users" (pgsql) and `users` (mysql) are identifiers, not string
        // literals — collapsing them would merge different columns.
        expect($this->fingerprinter->fingerprint('select "col_a" from "t" where "col_a" = ?'))
            ->not->toBe($this->fingerprinter->fingerprint('select "col_b" from "t" where "col_b" = ?'))
            ->and($this->fingerprinter->fingerprint('select `col_a` from `t` where `col_a` = ?'))
            ->not->toBe($this->fingerprinter->fingerprint('select `col_b` from `t` where `col_b` = ?'));
    });

    it('exposes the normalized form for inspection', function () {
        expect($this->fingerprinter->normalize("SELECT *  FROM `users`\nWHERE id IN (1, 2, 3) -- x"))
            ->toBe('select * from `users` where id in (?)');
    });
});
