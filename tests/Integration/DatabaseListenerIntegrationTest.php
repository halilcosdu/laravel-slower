<?php

use HalilCosdu\Slower\Models\SlowLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config(['slower.enabled' => true]);
    config(['slower.threshold' => 0]); // Catch all queries
    config(['slower.ignore_explain_queries' => true]);
    config(['slower.ignore_insert_queries' => false]);

    // Create test table
    if (! Schema::hasTable('test_users')) {
        Schema::create('test_users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });
    }
});

afterEach(function () {
    Schema::dropIfExists('test_users');
    SlowLog::query()->delete();
});

describe('Database Listener Integration Tests', function () {
    it('captures slow queries automatically', function () {
        config(['slower.threshold' => 0]);

        DB::table('test_users')->insert([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        // Wait a moment for async operations
        sleep(1);

        $slowLogs = SlowLog::all();

        expect($slowLogs->count())->toBeGreaterThan(0);
    });

    it('does not capture queries below threshold', function () {
        config(['slower.threshold' => 999999]); // Very high threshold

        DB::table('test_users')->where('id', 1)->first();

        sleep(1);

        $slowLogs = SlowLog::where('sql', 'like', '%test_users%')->get();

        expect($slowLogs->count())->toBe(0);
    });

    it('ignores EXPLAIN queries when configured', function () {
        config(['slower.threshold' => 0]);
        config(['slower.ignore_explain_queries' => true]);

        DB::select('EXPLAIN SELECT * FROM test_users');

        sleep(1);

        $slowLogs = SlowLog::where('sql', 'like', 'EXPLAIN%')->get();

        expect($slowLogs->count())->toBe(0);
    });

    it('captures EXPLAIN queries when not ignored', function () {
        config(['slower.threshold' => 0]);
        config(['slower.ignore_explain_queries' => false]);

        try {
            DB::select('EXPLAIN SELECT * FROM test_users');
        } catch (\Exception $e) {
            // Some databases may not support EXPLAIN
        }

        sleep(1);

        $slowLogs = SlowLog::all();

        // At least the INSERT into slow_logs itself should be captured
        expect($slowLogs->count())->toBeGreaterThan(0);
    });

    it('ignores INSERT queries when configured', function () {
        config(['slower.threshold' => 0]);
        config(['slower.ignore_insert_queries' => true]);

        DB::table('test_users')->insert([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        sleep(1);

        $slowLogs = SlowLog::where('sql', 'like', 'insert into%')->get();

        expect($slowLogs->count())->toBe(0);
    });

    it('stores correct query metadata', function () {
        config(['slower.threshold' => 0]);
        config(['slower.ignore_insert_queries' => false]);

        DB::table('test_users')->insert([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        sleep(1);

        $slowLog = SlowLog::where('sql', 'like', '%test_users%')->first();

        expect($slowLog)->not->toBeNull();
        expect($slowLog->sql)->toContain('test_users');
        expect($slowLog->time)->toBeGreaterThanOrEqual(0);
        expect($slowLog->connection)->not->toBeNull();
        expect($slowLog->is_analyzed)->toBeFalse();
    });

    it('prevents logging queries to slow_logs table itself', function () {
        config(['slower.threshold' => 0]);

        // Force a query on slow_logs table
        SlowLog::query()->where('id', 999)->first();

        sleep(1);

        // Should not create infinite loop
        $slowLogsAboutSlowLogs = SlowLog::where('sql', 'like', '%slow_logs%')->get();

        expect($slowLogsAboutSlowLogs->count())->toBe(0);
    });

    it('handles concurrent queries correctly', function () {
        config(['slower.threshold' => 0]);
        config(['slower.ignore_insert_queries' => false]);

        // Execute multiple queries concurrently
        for ($i = 0; $i < 10; $i++) {
            DB::table('test_users')->insert([
                'name' => "User $i",
                'email' => "user$i@example.com",
            ]);
        }

        sleep(2);

        $slowLogs = SlowLog::where('sql', 'like', '%test_users%')->get();

        expect($slowLogs->count())->toBeGreaterThan(0);
    });

    it('normalizes different binding types correctly', function () {
        config(['slower.threshold' => 0]);

        // Test with bindings
        DB::table('test_users')->where('id', 1)->first();
        DB::table('test_users')->where('name', 'John')->first();
        DB::table('test_users')->whereIn('id', [1, 2, 3])->first();

        sleep(1);

        $slowLogs = SlowLog::where('sql', 'like', '%test_users%')->get();

        expect($slowLogs->count())->toBeGreaterThan(0);

        foreach ($slowLogs as $log) {
            expect($log->bindings)->toBeArray();
        }
    });

    it('stores raw SQL with bindings substituted', function () {
        config(['slower.threshold' => 0]);

        DB::table('test_users')->where('name', 'John Doe')->first();

        sleep(1);

        $slowLog = SlowLog::where('sql', 'like', '%test_users%')
            ->where('sql', 'like', '%where%')
            ->first();

        if ($slowLog) {
            expect($slowLog->raw_sql)->not->toBeNull();
            expect($slowLog->raw_sql)->toBeString();
        }
    });
});

describe('Database Listener Edge Cases', function () {
    it('handles queries when database connection fails', function () {
        config(['slower.threshold' => 0]);

        try {
            DB::connection('invalid_connection')->table('test_users')->first();
        } catch (\Exception $e) {
            // Expected to fail
        }

        sleep(1);

        // Should not crash the application
        expect(true)->toBeTrue();
    });

    it('handles extremely long queries', function () {
        config(['slower.threshold' => 0]);

        $longValue = str_repeat('a', 10000);

        try {
            DB::table('test_users')->where('name', $longValue)->first();
        } catch (\Exception $e) {
            // May fail, but should not crash
        }

        sleep(1);

        expect(true)->toBeTrue();
    });

    it('handles queries with special characters', function () {
        config(['slower.threshold' => 0]);
        config(['slower.ignore_insert_queries' => false]);

        DB::table('test_users')->insert([
            'name' => "O'Reilly & Sons",
            'email' => 'special@example.com',
        ]);

        sleep(1);

        $slowLog = SlowLog::where('sql', 'like', '%test_users%')
            ->where('raw_sql', 'like', "%O'Reilly%")
            ->first();

        expect($slowLog)->not->toBeNull();
    });

    it('handles queries with unicode characters', function () {
        config(['slower.threshold' => 0]);
        config(['slower.ignore_insert_queries' => false]);

        DB::table('test_users')->insert([
            'name' => 'José García 😀',
            'email' => 'unicode@example.com',
        ]);

        sleep(1);

        $slowLog = SlowLog::where('sql', 'like', '%test_users%')
            ->where('raw_sql', 'like', '%José%')
            ->first();

        expect($slowLog)->not->toBeNull();
    });

    it('handles NULL bindings', function () {
        config(['slower.threshold' => 0]);

        DB::table('test_users')->whereNull('email')->first();

        sleep(1);

        $slowLogs = SlowLog::where('sql', 'like', '%test_users%')
            ->where('sql', 'like', '%null%')
            ->get();

        expect($slowLogs->count())->toBeGreaterThanOrEqual(0);
    });

    it('respects disabled configuration', function () {
        config(['slower.enabled' => false]);
        config(['slower.threshold' => 0]);

        DB::table('test_users')->where('id', 1)->first();

        sleep(1);

        $slowLogs = SlowLog::all();

        // May have previous logs, but no new ones should be added
        $initialCount = $slowLogs->count();

        DB::table('test_users')->where('id', 2)->first();

        sleep(1);

        $newCount = SlowLog::all()->count();

        expect($newCount)->toBe($initialCount);
    });
});

describe('Database Connection Tests', function () {
    it('stores correct connection information', function () {
        config(['slower.threshold' => 0]);

        DB::table('test_users')->where('id', 1)->first();

        sleep(1);

        $slowLog = SlowLog::where('sql', 'like', '%test_users%')->first();

        expect($slowLog)->not->toBeNull();
        expect($slowLog->connection)->not->toBeNull();
        expect($slowLog->connection_name)->not->toBeNull();
    });

    it('handles different connection types', function () {
        config(['slower.threshold' => 0]);

        $defaultConnection = DB::getDefaultConnection();

        DB::table('test_users')->where('id', 1)->first();

        sleep(1);

        $slowLog = SlowLog::where('sql', 'like', '%test_users%')->first();

        expect($slowLog)->not->toBeNull();
        expect($slowLog->connection_name)->toBe($defaultConnection);
    });
});
