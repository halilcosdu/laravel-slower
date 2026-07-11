<?php

use HalilCosdu\Slower\Support\SqlFormatter;

describe('SqlFormatter', function () {
    it('breaks lines before top-level keywords', function () {
        expect(SqlFormatter::format('select * from users where id = 1 order by id limit 5'))
            ->toBe("select *\nfrom users\nwhere id = 1\norder by id\nlimit 5");
    });

    it('breaks before join clauses as a unit', function () {
        expect(SqlFormatter::format('select * from posts inner join users on users.id = posts.user_id where posts.active = 1'))
            ->toBe("select *\nfrom posts\ninner join users on users.id = posts.user_id\nwhere posts.active = 1");
    });

    it('never touches keyword lookalikes inside string literals', function () {
        expect(SqlFormatter::format("select * from logs where message = 'copied from users limit reached'"))
            ->toBe("select *\nfrom logs\nwhere message = 'copied from users limit reached'");
    });

    it('never touches quoted identifiers', function () {
        expect(SqlFormatter::format('select "from" from "group by table" where id = 1'))
            ->toBe("select \"from\"\nfrom \"group by table\"\nwhere id = 1");
    });

    it('preserves the original character case', function () {
        expect(SqlFormatter::format('SELECT * FROM Users WHERE Id = 1'))
            ->toBe("SELECT *\nFROM Users\nWHERE Id = 1");
    });
});
