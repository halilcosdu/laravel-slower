<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

describe('v3.2 schema', function () {
    it('creates the fingerprint and origin columns on fresh installs', function () {
        $table = config('slower.resources.table_name');

        expect(Schema::hasColumns($table, ['fingerprint', 'fingerprint_version', 'origin']))->toBeTrue();
    });

    it('upgrades a pre-3.2 table and is idempotent', function () {
        $table = config('slower.resources.table_name');

        // Rebuild the table in its pre-3.2 shape.
        Schema::dropIfExists($table);
        Schema::create($table, function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->boolean('is_analyzed')->default(false)->index();
            $blueprint->longtext('bindings');
            $blueprint->longtext('sql');
            $blueprint->float('time')->nullable()->index();
            $blueprint->string('connection');
            $blueprint->string('connection_name')->nullable();
            $blueprint->longtext('raw_sql');
            $blueprint->longtext('recommendation')->nullable();
            $blueprint->timestamps();
        });

        $migration = include __DIR__.'/../database/migrations/add_slower_v32_columns.php.stub';

        $migration->up();
        expect(Schema::hasColumns($table, ['fingerprint', 'fingerprint_version', 'origin']))->toBeTrue();

        // Running it again (fresh installs publish both migrations) must be a no-op.
        $migration->up();
        expect(Schema::hasColumns($table, ['fingerprint', 'fingerprint_version', 'origin']))->toBeTrue();

        $migration->down();
        expect(Schema::hasColumn($table, 'fingerprint'))->toBeFalse();

        // Restore the standard v3.2 table for any tests that follow in this process.
        Schema::dropIfExists($table);
        $create = include __DIR__.'/../database/migrations/create_slower_table.php.stub';
        $create->up();
    });
});
