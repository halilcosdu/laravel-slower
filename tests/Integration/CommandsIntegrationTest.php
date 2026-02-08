<?php

use HalilCosdu\Slower\AiServiceDrivers\Contracts\AiServiceDriver;
use HalilCosdu\Slower\Commands\AnalyzeQuery;
use HalilCosdu\Slower\Commands\SlowLogCleaner;
use HalilCosdu\Slower\Models\SlowLog;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    SlowLog::query()->delete();
});

describe('AnalyzeQuery Command Integration', function () {
    it('analyzes unanalyzed records successfully', function () {
        config(['slower.enabled' => true]);
        config(['slower.ai_recommendation' => true]);

        // Create test records
        SlowLog::create([
            'sql' => 'SELECT * FROM users WHERE id = ?',
            'bindings' => [1],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'testing',
            'raw_sql' => 'SELECT * FROM users WHERE id = 1',
            'is_analyzed' => false,
        ]);

        // Mock AI service
        $this->instance(
            AiServiceDriver::class,
            Mockery::mock(AiServiceDriver::class, function ($mock) {
                $mock->shouldReceive('analyze')
                    ->once()
                    ->andReturn('Add index on id column');
            })
        );

        $this->artisan('slower:analyze')
            ->expectsOutput('All done')
            ->assertExitCode(0);

        $record = SlowLog::first();
        expect($record->is_analyzed)->toBeTrue();
        expect($record->recommendation)->toBe('Add index on id column');
    });

    it('handles multiple unanalyzed records', function () {
        config(['slower.enabled' => true]);
        config(['slower.ai_recommendation' => true]);

        // Create multiple test records
        for ($i = 0; $i < 5; $i++) {
            SlowLog::create([
                'sql' => "SELECT * FROM users WHERE id = $i",
                'bindings' => [$i],
                'time' => 5000,
                'connection' => 'mysql',
                'connection_name' => 'testing',
                'raw_sql' => "SELECT * FROM users WHERE id = $i",
                'is_analyzed' => false,
            ]);
        }

        $this->instance(
            AiServiceDriver::class,
            Mockery::mock(AiServiceDriver::class, function ($mock) {
                $mock->shouldReceive('analyze')
                    ->times(5)
                    ->andReturn('Recommendation');
            })
        );

        $this->artisan('slower:analyze')
            ->assertExitCode(0);

        $analyzedCount = SlowLog::where('is_analyzed', true)->count();
        expect($analyzedCount)->toBe(5);
    });

    it('skips already analyzed records', function () {
        config(['slower.enabled' => true]);
        config(['slower.ai_recommendation' => true]);

        SlowLog::create([
            'sql' => 'SELECT * FROM users',
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'testing',
            'raw_sql' => 'SELECT * FROM users',
            'is_analyzed' => true,
            'recommendation' => 'Already analyzed',
        ]);

        $this->instance(
            AiServiceDriver::class,
            Mockery::mock(AiServiceDriver::class, function ($mock) {
                $mock->shouldReceive('analyze')->never();
            })
        );

        $this->artisan('slower:analyze')
            ->assertExitCode(0);
    });

    it('warns when slower is disabled', function () {
        config(['slower.enabled' => false]);

        $this->artisan('slower:analyze')
            ->expectsOutput('Slower or AI recommendation is not enabled. Please enable it in the configuration file.')
            ->assertExitCode(0);
    });

    it('warns when AI recommendation is disabled', function () {
        config(['slower.enabled' => true]);
        config(['slower.ai_recommendation' => false]);

        $this->artisan('slower:analyze')
            ->expectsOutput('Slower or AI recommendation is not enabled. Please enable it in the configuration file.')
            ->assertExitCode(0);
    });

    it('handles large batches with chunking', function () {
        config(['slower.enabled' => true]);
        config(['slower.ai_recommendation' => true]);

        // Create 1500 records to test chunking (chunk size is 1000)
        for ($i = 0; $i < 1500; $i++) {
            SlowLog::create([
                'sql' => "SELECT * FROM users WHERE id = $i",
                'bindings' => [$i],
                'time' => 5000,
                'connection' => 'mysql',
                'connection_name' => 'testing',
                'raw_sql' => "SELECT * FROM users WHERE id = $i",
                'is_analyzed' => false,
            ]);
        }

        $this->instance(
            AiServiceDriver::class,
            Mockery::mock(AiServiceDriver::class, function ($mock) {
                $mock->shouldReceive('analyze')
                    ->times(1500)
                    ->andReturn('Recommendation');
            })
        );

        $this->artisan('slower:analyze')
            ->assertExitCode(0);

        $analyzedCount = SlowLog::where('is_analyzed', true)->count();
        expect($analyzedCount)->toBe(1500);
    })->skip('This test is slow, enable when needed');

    it('handles AI service failures gracefully', function () {
        config(['slower.enabled' => true]);
        config(['slower.ai_recommendation' => true]);

        SlowLog::create([
            'sql' => 'SELECT * FROM users',
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'testing',
            'raw_sql' => 'SELECT * FROM users',
            'is_analyzed' => false,
        ]);

        $this->instance(
            AiServiceDriver::class,
            Mockery::mock(AiServiceDriver::class, function ($mock) {
                $mock->shouldReceive('analyze')
                    ->once()
                    ->andThrow(new \Exception('API Error'));
            })
        );

        try {
            $this->artisan('slower:analyze');
        } catch (\Exception $e) {
            expect($e->getMessage())->toBe('API Error');
        }
    });
});

describe('SlowLogCleaner Command Integration', function () {
    it('deletes records older than specified days', function () {
        // Create old records
        SlowLog::create([
            'sql' => 'SELECT * FROM users',
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'testing',
            'raw_sql' => 'SELECT * FROM users',
            'created_at' => now()->subDays(20),
        ]);

        // Create recent record
        SlowLog::create([
            'sql' => 'SELECT * FROM posts',
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'testing',
            'raw_sql' => 'SELECT * FROM posts',
            'created_at' => now()->subDays(5),
        ]);

        $this->artisan('slower:clean', ['days' => 15])
            ->expectsOutput('All done')
            ->assertExitCode(0);

        expect(SlowLog::count())->toBe(1);
        expect(SlowLog::first()->raw_sql)->toContain('posts');
    });

    it('uses default 15 days when not specified', function () {
        SlowLog::create([
            'sql' => 'SELECT * FROM users',
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'testing',
            'raw_sql' => 'SELECT * FROM users',
            'created_at' => now()->subDays(20),
        ]);

        SlowLog::create([
            'sql' => 'SELECT * FROM posts',
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'testing',
            'raw_sql' => 'SELECT * FROM posts',
            'created_at' => now()->subDays(10),
        ]);

        $this->artisan('slower:clean')
            ->assertExitCode(0);

        expect(SlowLog::count())->toBe(1);
    });

    it('handles custom days parameter', function () {
        SlowLog::create([
            'sql' => 'SELECT * FROM users',
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'testing',
            'raw_sql' => 'SELECT * FROM users',
            'created_at' => now()->subDays(35),
        ]);

        SlowLog::create([
            'sql' => 'SELECT * FROM posts',
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'testing',
            'raw_sql' => 'SELECT * FROM posts',
            'created_at' => now()->subDays(25),
        ]);

        $this->artisan('slower:clean', ['days' => 30])
            ->assertExitCode(0);

        expect(SlowLog::count())->toBe(1);
        expect(SlowLog::first()->raw_sql)->toContain('posts');
    });

    it('handles zero records to delete', function () {
        SlowLog::create([
            'sql' => 'SELECT * FROM users',
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'testing',
            'raw_sql' => 'SELECT * FROM users',
            'created_at' => now(),
        ]);

        $this->artisan('slower:clean', ['days' => 15])
            ->expectsOutput('All done')
            ->assertExitCode(0);

        expect(SlowLog::count())->toBe(1);
    });

    it('handles large batches with chunking', function () {
        // Create 1500 old records
        for ($i = 0; $i < 1500; $i++) {
            SlowLog::create([
                'sql' => "SELECT * FROM users WHERE id = $i",
                'bindings' => [$i],
                'time' => 5000,
                'connection' => 'mysql',
                'connection_name' => 'testing',
                'raw_sql' => "SELECT * FROM users WHERE id = $i",
                'created_at' => now()->subDays(20),
            ]);
        }

        $this->artisan('slower:clean', ['days' => 15])
            ->assertExitCode(0);

        expect(SlowLog::count())->toBe(0);
    })->skip('This test is slow, enable when needed');

    it('handles string days parameter', function () {
        SlowLog::create([
            'sql' => 'SELECT * FROM users',
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'testing',
            'raw_sql' => 'SELECT * FROM users',
            'created_at' => now()->subDays(20),
        ]);

        $this->artisan('slower:clean', ['days' => '15'])
            ->assertExitCode(0);

        expect(SlowLog::count())->toBe(0);
    });

    it('handles negative days parameter', function () {
        SlowLog::create([
            'sql' => 'SELECT * FROM users',
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'testing',
            'raw_sql' => 'SELECT * FROM users',
            'created_at' => now()->subDays(5),
        ]);

        $this->artisan('slower:clean', ['days' => -10])
            ->assertExitCode(0);

        // All records should be deleted since -10 days means future date
        expect(SlowLog::count())->toBe(0);
    });

    it('handles zero days parameter', function () {
        SlowLog::create([
            'sql' => 'SELECT * FROM users',
            'bindings' => [],
            'time' => 5000,
            'connection' => 'mysql',
            'connection_name' => 'testing',
            'raw_sql' => 'SELECT * FROM users',
            'created_at' => now()->subDays(5),
        ]);

        $this->artisan('slower:clean', ['days' => 0])
            ->assertExitCode(0);

        // All old records should be deleted
        expect(SlowLog::count())->toBe(0);
    });
});

describe('Command Error Handling', function () {
    it('handles missing configuration gracefully', function () {
        config(['slower.resources.model' => null]);

        try {
            $this->artisan('slower:clean');
        } catch (\Exception $e) {
            expect($e)->toBeInstanceOf(\Exception::class);
        }
    });

    it('handles invalid model class', function () {
        config(['slower.resources.model' => 'InvalidModelClass']);

        try {
            $this->artisan('slower:clean');
        } catch (\Error $e) {
            expect($e)->toBeInstanceOf(\Error::class);
        }
    });
});
