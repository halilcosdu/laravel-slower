<?php

use HalilCosdu\Slower\AiServiceDrivers\Contracts\AiServiceDriver;
use HalilCosdu\Slower\Facades\Slower;
use HalilCosdu\Slower\Models\SlowLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    SlowLog::query()->delete();

    // Create test table
    if (! Schema::hasTable('feature_test_users')) {
        Schema::create('feature_test_users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->integer('age')->nullable();
            $table->timestamps();
        });
    }

    // Seed some data
    for ($i = 1; $i <= 100; $i++) {
        DB::table('feature_test_users')->insert([
            'name' => "User $i",
            'email' => "user$i@example.com",
            'age' => rand(18, 80),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
});

afterEach(function () {
    Schema::dropIfExists('feature_test_users');
    SlowLog::query()->delete();
});

describe('End-to-End Package Workflow', function () {
    it('captures, analyzes, and cleans slow queries in full workflow', function () {
        config(['slower.enabled' => true]);
        config(['slower.threshold' => 0]);
        config(['slower.ai_recommendation' => true]);
        config(['slower.ignore_insert_queries' => true]);

        // Step 1: Execute a slow query
        DB::table('feature_test_users')->where('age', '>', 50)->get();

        sleep(1);

        // Verify query was captured
        $captured = SlowLog::where('sql', 'like', '%feature_test_users%')->first();
        expect($captured)->not->toBeNull();
        expect($captured->is_analyzed)->toBeFalse();

        // Step 2: Analyze the query
        $this->instance(
            AiServiceDriver::class,
            Mockery::mock(AiServiceDriver::class, function ($mock) {
                $mock->shouldReceive('analyze')->once()->andReturn('Add index on age column');
            })
        );

        $result = Slower::analyze($captured);

        expect($result->is_analyzed)->toBeTrue();
        expect($result->recommendation)->toBe('Add index on age column');

        // Step 3: Clean old records
        $captured->update(['created_at' => now()->subDays(20)]);

        $this->artisan('slower:clean', ['days' => 15])->assertExitCode(0);

        expect(SlowLog::count())->toBe(0);
    });

    it('handles complete lifecycle with multiple queries', function () {
        config(['slower.enabled' => true]);
        config(['slower.threshold' => 0]);
        config(['slower.ai_recommendation' => true]);

        // Execute multiple different queries
        DB::table('feature_test_users')->where('age', '>', 30)->get();
        DB::table('feature_test_users')->where('name', 'like', '%User%')->limit(10)->get();
        DB::table('feature_test_users')->orderBy('created_at', 'desc')->first();

        sleep(1);

        $slowLogs = SlowLog::where('sql', 'like', '%feature_test_users%')->get();
        expect($slowLogs->count())->toBeGreaterThan(0);

        // Analyze all
        $this->instance(
            AiServiceDriver::class,
            Mockery::mock(AiServiceDriver::class, function ($mock) use ($slowLogs) {
                $mock->shouldReceive('analyze')
                    ->times($slowLogs->count())
                    ->andReturn('Optimization suggestion');
            })
        );

        foreach ($slowLogs as $log) {
            Slower::analyze($log);
        }

        $analyzed = SlowLog::where('is_analyzed', true)->count();
        expect($analyzed)->toBe($slowLogs->count());
    });
});

describe('Real-World Query Patterns', function () {
    it('handles complex JOIN queries', function () {
        config(['slower.enabled' => true]);
        config(['slower.threshold' => 0]);

        // Create related table
        Schema::create('feature_test_orders', function ($table) {
            $table->id();
            $table->foreignId('user_id');
            $table->decimal('amount', 10, 2);
            $table->timestamps();
        });

        // Execute JOIN query
        DB::table('feature_test_users')
            ->join('feature_test_orders', 'feature_test_users.id', '=', 'feature_test_orders.user_id')
            ->select('feature_test_users.*', DB::raw('SUM(feature_test_orders.amount) as total'))
            ->groupBy('feature_test_users.id')
            ->get();

        sleep(1);

        $log = SlowLog::where('sql', 'like', '%join%')->first();
        expect($log)->not->toBeNull();

        Schema::dropIfExists('feature_test_orders');
    });

    it('handles aggregate queries', function () {
        config(['slower.enabled' => true]);
        config(['slower.threshold' => 0]);

        DB::table('feature_test_users')
            ->select(DB::raw('age, COUNT(*) as count'))
            ->groupBy('age')
            ->having('count', '>', 1)
            ->get();

        sleep(1);

        $log = SlowLog::where('sql', 'like', '%group by%')->first();
        expect($log)->not->toBeNull();
    });

    it('handles subqueries', function () {
        config(['slower.enabled' => true]);
        config(['slower.threshold' => 0]);

        DB::table('feature_test_users')
            ->whereIn('id', function ($query) {
                $query->select('id')
                    ->from('feature_test_users')
                    ->where('age', '>', 50);
            })
            ->get();

        sleep(1);

        $logs = SlowLog::where('sql', 'like', '%feature_test_users%')->get();
        expect($logs->count())->toBeGreaterThan(0);
    });

    it('handles LIKE queries', function () {
        config(['slower.enabled' => true]);
        config(['slower.threshold' => 0]);

        DB::table('feature_test_users')
            ->where('name', 'like', '%User 1%')
            ->orWhere('email', 'like', '%user1%')
            ->get();

        sleep(1);

        $log = SlowLog::where('sql', 'like', '%like%')->first();
        expect($log)->not->toBeNull();
    });

    it('handles ORDER BY with LIMIT', function () {
        config(['slower.enabled' => true]);
        config(['slower.threshold' => 0]);

        DB::table('feature_test_users')
            ->orderBy('created_at', 'desc')
            ->orderBy('name', 'asc')
            ->limit(20)
            ->get();

        sleep(1);

        $log = SlowLog::where('sql', 'like', '%order by%')->first();
        expect($log)->not->toBeNull();
    });
});

describe('Configuration Scenarios', function () {
    it('respects threshold configuration', function () {
        config(['slower.enabled' => true]);
        config(['slower.threshold' => 999999]); // Very high threshold

        DB::table('feature_test_users')->limit(1)->get();

        sleep(1);

        $logs = SlowLog::where('sql', 'like', '%feature_test_users%')->get();
        expect($logs->count())->toBe(0);
    });

    it('disables capturing when configured', function () {
        config(['slower.enabled' => false]);

        DB::table('feature_test_users')->get();

        sleep(1);

        $logs = SlowLog::all();
        $initialCount = $logs->count();

        DB::table('feature_test_users')->where('id', 1)->first();

        sleep(1);

        expect(SlowLog::count())->toBe($initialCount);
    });

    it('handles AI recommendation toggle', function () {
        config(['slower.ai_recommendation' => false]);

        $log = SlowLog::create([
            'sql' => 'SELECT * FROM users',
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'testing',
            'raw_sql' => 'SELECT * FROM users',
        ]);

        $this->instance(
            AiServiceDriver::class,
            Mockery::mock(AiServiceDriver::class, function ($mock) {
                $mock->shouldReceive('analyze')->never();
            })
        );

        $result = Slower::analyze($log);

        expect($result->recommendation)->toBeNull();
    });
});

describe('Performance Under Load', function () {
    it('handles rapid consecutive queries', function () {
        config(['slower.enabled' => true]);
        config(['slower.threshold' => 0]);

        // Execute 50 queries rapidly
        for ($i = 0; $i < 50; $i++) {
            DB::table('feature_test_users')->where('id', $i)->first();
        }

        sleep(2);

        $logs = SlowLog::where('sql', 'like', '%feature_test_users%')->get();
        expect($logs->count())->toBeGreaterThan(0);
    });

    it('handles concurrent different query types', function () {
        config(['slower.enabled' => true]);
        config(['slower.threshold' => 0]);

        // Mix of query types
        DB::table('feature_test_users')->count();
        DB::table('feature_test_users')->where('age', '>', 30)->get();
        DB::table('feature_test_users')->orderBy('name')->limit(5)->get();
        DB::table('feature_test_users')->select('age', DB::raw('COUNT(*) as total'))->groupBy('age')->get();

        sleep(1);

        $logs = SlowLog::where('sql', 'like', '%feature_test_users%')->get();
        expect($logs->count())->toBeGreaterThan(0);
    });
});

describe('Edge Case Scenarios', function () {
    it('handles queries with NULL values', function () {
        config(['slower.enabled' => true]);
        config(['slower.threshold' => 0]);

        DB::table('feature_test_users')->whereNull('age')->get();

        sleep(1);

        $log = SlowLog::where('sql', 'like', '%null%')->first();
        expect($log)->not->toBeNull();
    });

    it('handles queries with IN clauses', function () {
        config(['slower.enabled' => true]);
        config(['slower.threshold' => 0]);

        DB::table('feature_test_users')->whereIn('id', [1, 2, 3, 4, 5])->get();

        sleep(1);

        $log = SlowLog::where('sql', 'like', '%feature_test_users%')->first();
        expect($log)->not->toBeNull();
    });

    it('handles queries with BETWEEN', function () {
        config(['slower.enabled' => true]);
        config(['slower.threshold' => 0]);

        DB::table('feature_test_users')->whereBetween('age', [25, 50])->get();

        sleep(1);

        $log = SlowLog::where('sql', 'like', '%between%')->first();
        expect($log)->not->toBeNull();
    });

    it('handles raw SQL expressions', function () {
        config(['slower.enabled' => true]);
        config(['slower.threshold' => 0]);

        DB::table('feature_test_users')
            ->select(DB::raw('*, YEAR(created_at) as year'))
            ->get();

        sleep(1);

        $log = SlowLog::where('sql', 'like', '%feature_test_users%')->first();
        expect($log)->not->toBeNull();
    });

    it('prevents infinite loops with slow_logs table', function () {
        config(['slower.enabled' => true]);
        config(['slower.threshold' => 0]);

        // Query slow_logs table itself
        SlowLog::where('id', 999)->first();

        sleep(1);

        // Should not create logs about querying slow_logs
        $selfLogs = SlowLog::where('sql', 'like', '%slow_logs%')->get();
        expect($selfLogs->count())->toBe(0);
    });
});

describe('Error Recovery', function () {
    it('continues functioning after database errors', function () {
        config(['slower.enabled' => true]);
        config(['slower.threshold' => 0]);

        // Execute valid query
        DB::table('feature_test_users')->first();

        try {
            // Execute invalid query
            DB::table('non_existent_table')->first();
        } catch (\Exception $e) {
            // Expected to fail
        }

        // Execute another valid query
        DB::table('feature_test_users')->where('id', 1)->first();

        sleep(1);

        // Should have captured the valid queries
        $logs = SlowLog::where('sql', 'like', '%feature_test_users%')->get();
        expect($logs->count())->toBeGreaterThan(0);
    });

    it('handles malformed bindings gracefully', function () {
        config(['slower.enabled' => true]);
        config(['slower.threshold' => 0]);

        DB::table('feature_test_users')->where('id', 1)->first();

        sleep(1);

        $log = SlowLog::where('sql', 'like', '%feature_test_users%')->first();
        expect($log)->not->toBeNull();
        expect($log->bindings)->toBeArray();
    });
});
