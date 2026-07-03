<?php

use HalilCosdu\Slower\AiServiceDrivers\Contracts\AiServiceDriver;
use HalilCosdu\Slower\Commands\AnalyzeQuery;
use HalilCosdu\Slower\Commands\SlowLogCleaner;
use HalilCosdu\Slower\Models\SlowLog;

describe('AnalyzeQuery command', function () {
    it('has correct signature', function () {
        $command = new AnalyzeQuery;
        expect($command->signature)->toBe('slower:analyze');
    });

    it('has correct description', function () {
        $command = new AnalyzeQuery;
        expect($command->description)->toBe('Analyze and generate a recommendation for the given record.');
    });

    it('warns and exits successfully when AI recommendation is disabled', function () {
        config(['slower.enabled' => true]);
        config(['slower.ai_recommendation' => false]);

        $this->artisan('slower:analyze')->assertSuccessful();
    });

    it('analyzes pending records and marks them analyzed', function () {
        $ai = Mockery::mock(AiServiceDriver::class);
        $ai->shouldReceive('analyze')->andReturn('Add an index on (id).');
        $this->app->instance(AiServiceDriver::class, $ai);

        SlowLog::factory()->count(2)->create([
            'is_analyzed' => false,
            'raw_sql' => 'select * from '.config('slower.resources.table_name'),
        ]);

        $this->artisan('slower:analyze')->assertSuccessful();

        expect(SlowLog::where('is_analyzed', true)->count())->toBe(2);
    });

    it('keeps records retryable when the AI returns an empty recommendation', function () {
        $ai = Mockery::mock(AiServiceDriver::class);
        $ai->shouldReceive('analyze')->andReturn(null);
        $this->app->instance(AiServiceDriver::class, $ai);

        SlowLog::factory()->create([
            'is_analyzed' => false,
            'raw_sql' => 'select * from '.config('slower.resources.table_name'),
        ]);

        $this->artisan('slower:analyze')->assertSuccessful();

        expect(SlowLog::first()->is_analyzed)->toBeFalse();
    });
});

describe('SlowLogCleaner command', function () {
    it('has correct signature', function () {
        $command = new SlowLogCleaner;
        expect($command->signature)->toBe('slower:clean {days=15}');
    });

    it('has correct description', function () {
        $command = new SlowLogCleaner;
        expect($command->description)->toBe('Delete records older than 15 days.');
    });

    it('deletes old records and keeps recent ones', function () {
        $old = SlowLog::factory()->create(['created_at' => now()->subDays(30)]);
        $recent = SlowLog::factory()->create(['created_at' => now()->subDays(1)]);

        $this->artisan('slower:clean', ['days' => 15])->assertSuccessful();

        expect(SlowLog::find($old->id))->toBeNull();
        expect(SlowLog::find($recent->id))->not->toBeNull();
    });
});
