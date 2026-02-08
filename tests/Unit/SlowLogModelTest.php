<?php

use HalilCosdu\Slower\Models\SlowLog;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    SlowLog::query()->delete();
});

describe('SlowLog Model', function () {
    it('creates a record with all required fields', function () {
        $log = SlowLog::create([
            'sql' => 'SELECT * FROM users WHERE id = ?',
            'bindings' => [1],
            'time' => 5000.5,
            'connection' => 'Illuminate\Database\MySqlConnection',
            'connection_name' => 'mysql',
            'raw_sql' => 'SELECT * FROM users WHERE id = 1',
        ]);

        expect($log)->toBeInstanceOf(SlowLog::class);
        expect($log->sql)->toBe('SELECT * FROM users WHERE id = ?');
        expect($log->bindings)->toBe([1]);
        expect($log->time)->toBe(5000.5);
        expect($log->connection)->toBe('Illuminate\Database\MySqlConnection');
        expect($log->connection_name)->toBe('mysql');
        expect($log->raw_sql)->toBe('SELECT * FROM users WHERE id = 1');
        expect($log->is_analyzed)->toBeFalse();
    });

    it('casts is_analyzed to boolean', function () {
        $log = SlowLog::create([
            'sql' => 'SELECT * FROM users',
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'mysql',
            'raw_sql' => 'SELECT * FROM users',
            'is_analyzed' => 1,
        ]);

        expect($log->is_analyzed)->toBeBool();
        expect($log->is_analyzed)->toBeTrue();
    });

    it('casts bindings to array', function () {
        $log = SlowLog::create([
            'sql' => 'SELECT * FROM users WHERE id = ?',
            'bindings' => [1, 'test', null],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'mysql',
            'raw_sql' => 'SELECT * FROM users WHERE id = 1',
        ]);

        expect($log->bindings)->toBeArray();
        expect($log->bindings)->toBe([1, 'test', null]);
    });

    it('casts time to float', function () {
        $log = SlowLog::create([
            'sql' => 'SELECT * FROM users',
            'bindings' => [],
            'time' => '5000.75',
            'connection' => 'mysql',
            'connection_name' => 'mysql',
            'raw_sql' => 'SELECT * FROM users',
        ]);

        expect($log->time)->toBeFloat();
        expect($log->time)->toBe(5000.75);
    });

    it('stores recommendation when provided', function () {
        $log = SlowLog::create([
            'sql' => 'SELECT * FROM users',
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'mysql',
            'raw_sql' => 'SELECT * FROM users',
            'recommendation' => 'Add index on id column',
        ]);

        expect($log->recommendation)->toBe('Add index on id column');
    });

    it('updates is_analyzed flag', function () {
        $log = SlowLog::create([
            'sql' => 'SELECT * FROM users',
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'mysql',
            'raw_sql' => 'SELECT * FROM users',
        ]);

        expect($log->is_analyzed)->toBeFalse();

        $log->update(['is_analyzed' => true]);

        expect($log->fresh()->is_analyzed)->toBeTrue();
    });

    it('has timestamps', function () {
        $log = SlowLog::create([
            'sql' => 'SELECT * FROM users',
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'mysql',
            'raw_sql' => 'SELECT * FROM users',
        ]);

        expect($log->created_at)->not->toBeNull();
        expect($log->updated_at)->not->toBeNull();
        expect($log->created_at)->toBeInstanceOf(\Carbon\Carbon::class);
        expect($log->updated_at)->toBeInstanceOf(\Carbon\Carbon::class);
    });
});

describe('SlowLog Model Edge Cases', function () {
    it('handles empty bindings array', function () {
        $log = SlowLog::create([
            'sql' => 'SELECT * FROM users',
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'mysql',
            'raw_sql' => 'SELECT * FROM users',
        ]);

        expect($log->bindings)->toBeArray();
        expect($log->bindings)->toBeEmpty();
    });

    it('handles very long SQL query', function () {
        $longSql = 'SELECT * FROM users WHERE id IN ('.implode(', ', range(1, 10000)).')';

        $log = SlowLog::create([
            'sql' => $longSql,
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'mysql',
            'raw_sql' => $longSql,
        ]);

        expect($log->sql)->toBe($longSql);
        expect(strlen($log->sql))->toBeGreaterThan(10000);
    });

    it('handles SQL with special characters', function () {
        $sql = "SELECT * FROM users WHERE name = 'O\\'Reilly & Sons'";

        $log = SlowLog::create([
            'sql' => $sql,
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'mysql',
            'raw_sql' => $sql,
        ]);

        expect($log->sql)->toBe($sql);
    });

    it('handles SQL with unicode characters', function () {
        $sql = 'SELECT * FROM users WHERE name = "José García 😀"';

        $log = SlowLog::create([
            'sql' => $sql,
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'mysql',
            'raw_sql' => $sql,
        ]);

        expect($log->sql)->toBe($sql);
    });

    it('handles SQL with newlines and tabs', function () {
        $sql = "SELECT *\n\tFROM users\n\tWHERE id = 1";

        $log = SlowLog::create([
            'sql' => $sql,
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'mysql',
            'raw_sql' => $sql,
        ]);

        expect($log->sql)->toBe($sql);
    });

    it('handles zero execution time', function () {
        $log = SlowLog::create([
            'sql' => 'SELECT * FROM users',
            'bindings' => [],
            'time' => 0.0,
            'connection' => 'mysql',
            'connection_name' => 'mysql',
            'raw_sql' => 'SELECT * FROM users',
        ]);

        expect($log->time)->toBe(0.0);
    });

    it('handles very large execution time', function () {
        $log = SlowLog::create([
            'sql' => 'SELECT * FROM users',
            'bindings' => [],
            'time' => 999999.99,
            'connection' => 'mysql',
            'connection_name' => 'mysql',
            'raw_sql' => 'SELECT * FROM users',
        ]);

        expect($log->time)->toBe(999999.99);
    });

    it('handles null recommendation', function () {
        $log = SlowLog::create([
            'sql' => 'SELECT * FROM users',
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'mysql',
            'raw_sql' => 'SELECT * FROM users',
            'recommendation' => null,
        ]);

        expect($log->recommendation)->toBeNull();
    });

    it('handles very long recommendation', function () {
        $longRecommendation = str_repeat('Add index. ', 1000);

        $log = SlowLog::create([
            'sql' => 'SELECT * FROM users',
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'mysql',
            'raw_sql' => 'SELECT * FROM users',
            'recommendation' => $longRecommendation,
        ]);

        expect($log->recommendation)->toBe($longRecommendation);
    });

    it('handles complex nested bindings', function () {
        $bindings = [
            'simple' => 'value',
            'number' => 123,
            'null' => null,
            'array' => [1, 2, 3],
            'nested' => ['key' => 'value'],
        ];

        $log = SlowLog::create([
            'sql' => 'SELECT * FROM users',
            'bindings' => $bindings,
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'mysql',
            'raw_sql' => 'SELECT * FROM users',
        ]);

        expect($log->bindings)->toBe($bindings);
    });

    it('handles different connection types', function () {
        $connections = [
            'Illuminate\Database\MySqlConnection',
            'Illuminate\Database\PostgresConnection',
            'Illuminate\Database\SQLiteConnection',
            'Illuminate\Database\SqlServerConnection',
        ];

        foreach ($connections as $connection) {
            $log = SlowLog::create([
                'sql' => 'SELECT * FROM users',
                'bindings' => [],
                'time' => 5000,
                'connection' => $connection,
                'connection_name' => 'testing',
                'raw_sql' => 'SELECT * FROM users',
            ]);

            expect($log->connection)->toBe($connection);
        }
    });
});

describe('SlowLog Query Scopes', function () {
    beforeEach(function () {
        // Create test data
        SlowLog::create([
            'sql' => 'SELECT * FROM users',
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'mysql',
            'raw_sql' => 'SELECT * FROM users',
            'is_analyzed' => false,
        ]);

        SlowLog::create([
            'sql' => 'SELECT * FROM posts',
            'bindings' => [],
            'time' => 3000,
            'connection' => 'mysql',
            'connection_name' => 'mysql',
            'raw_sql' => 'SELECT * FROM posts',
            'is_analyzed' => true,
            'recommendation' => 'Add index',
        ]);
    });

    it('can query unanalyzed records', function () {
        $unanalyzed = SlowLog::where('is_analyzed', false)->get();

        expect($unanalyzed->count())->toBe(1);
        expect($unanalyzed->first()->sql)->toContain('users');
    });

    it('can query analyzed records', function () {
        $analyzed = SlowLog::where('is_analyzed', true)->get();

        expect($analyzed->count())->toBe(1);
        expect($analyzed->first()->sql)->toContain('posts');
    });

    it('can filter by time threshold', function () {
        $slow = SlowLog::where('time', '>', 4000)->get();

        expect($slow->count())->toBe(1);
        expect($slow->first()->time)->toBe(5000.0);
    });

    it('can filter by connection', function () {
        $mysqlLogs = SlowLog::where('connection', 'mysql')->get();

        expect($mysqlLogs->count())->toBe(2);
    });

    it('can order by time', function () {
        $ordered = SlowLog::orderBy('time', 'desc')->get();

        expect($ordered->first()->time)->toBe(5000.0);
        expect($ordered->last()->time)->toBe(3000.0);
    });
});

describe('SlowLog Mass Assignment Protection', function () {
    it('allows mass assignment of fillable fields', function () {
        $log = SlowLog::create([
            'sql' => 'SELECT * FROM users',
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'mysql',
            'raw_sql' => 'SELECT * FROM users',
            'is_analyzed' => false,
            'recommendation' => 'Test',
        ]);

        expect($log->exists)->toBeTrue();
    });

    it('has correct fillable fields defined', function () {
        $log = new SlowLog;

        expect($log->getFillable())->toContain('sql');
        expect($log->getFillable())->toContain('bindings');
        expect($log->getFillable())->toContain('time');
        expect($log->getFillable())->toContain('connection');
        expect($log->getFillable())->toContain('connection_name');
        expect($log->getFillable())->toContain('raw_sql');
        expect($log->getFillable())->toContain('is_analyzed');
        expect($log->getFillable())->toContain('recommendation');
    });
});
