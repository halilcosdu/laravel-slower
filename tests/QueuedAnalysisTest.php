<?php

use HalilCosdu\Slower\AiServiceDrivers\Contracts\AiServiceDriver;
use HalilCosdu\Slower\Jobs\AnalyzeSlowLog;
use HalilCosdu\Slower\Models\SlowLog;
use HalilCosdu\Slower\Services\RecommendationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Gate::define('viewSlower', fn ($user = null) => true);
});

describe('the AnalyzeSlowLog job', function () {
    it('is queued and unique per record', function () {
        $record = SlowLog::factory()->create();
        $job = new AnalyzeSlowLog($record);

        expect($job)->toBeInstanceOf(ShouldQueue::class)
            ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
            ->and($job->uniqueId())->toBe((string) $record->getKey());
    });

    it('is discarded instead of failing when its record was pruned before pickup', function () {
        // slower:clean (or a dashboard delete) can remove a record after the
        // job is queued; the job must be dropped, not land in failed_jobs.
        expect((new AnalyzeSlowLog(SlowLog::factory()->create()))->deleteWhenMissingModels)->toBeTrue();
    });

    it('does not call the AI driver when recommendations are disabled', function () {
        config(['slower.ai_recommendation' => false]);

        $driver = Mockery::mock(AiServiceDriver::class);
        $driver->shouldReceive('analyze')->never();
        app()->instance(AiServiceDriver::class, $driver);

        (new AnalyzeSlowLog(SlowLog::factory()->create()))->handle(app(RecommendationService::class));
    });

    it('analyzes the record when handled', function () {
        $driver = Mockery::mock(AiServiceDriver::class);
        $driver->shouldReceive('analyze')->once()->andReturn('Add an index.');
        app()->instance(AiServiceDriver::class, $driver);

        $record = SlowLog::factory()->create([
            'raw_sql' => 'select * from '.config('slower.resources.table_name'),
        ]);

        (new AnalyzeSlowLog($record))->handle(app(RecommendationService::class));

        expect($record->refresh())
            ->is_analyzed->toBeTrue()
            ->recommendation->toBe('Add an index.');
    });
});

describe('dashboard queue mode', function () {
    it('dispatches to the configured queue instead of analyzing inline', function () {
        config(['slower.analyze_queue' => 'analysis']);
        Queue::fake();

        $record = SlowLog::factory()->create();

        $this->post(route('slower.analyze', $record))
            ->assertRedirect()
            ->assertSessionHas('slower.status');

        expect(session('slower.status'))->toContain('queued');

        Queue::assertPushedOn('analysis', AnalyzeSlowLog::class);
        expect($record->refresh()->is_analyzed)->toBeFalse();
    });

    it('stays synchronous when analyze_queue is null', function () {
        Queue::fake();

        $driver = Mockery::mock(AiServiceDriver::class);
        $driver->shouldReceive('analyze')->once()->andReturn('Inline result.');
        app()->instance(AiServiceDriver::class, $driver);

        $record = SlowLog::factory()->create([
            'raw_sql' => 'select * from '.config('slower.resources.table_name'),
        ]);

        $this->post(route('slower.analyze', $record))->assertRedirect();

        Queue::assertNothingPushed();
        expect($record->refresh()->is_analyzed)->toBeTrue();
    });

    it('queues pending analysis up to the configured limit', function () {
        config([
            'slower.analyze_queue' => 'analysis',
            'slower.dashboard.analyze_pending_limit' => 2,
        ]);
        Queue::fake();

        SlowLog::factory()->count(3)->create();

        $this->post(route('slower.analyze-pending'))
            ->assertRedirect()
            ->assertSessionHas('slower.status');

        Queue::assertPushed(AnalyzeSlowLog::class, 2);
        expect(session('slower.status'))->toContain('2');
    });
});

describe('slower:analyze --queue', function () {
    it('dispatches jobs instead of analyzing inline', function () {
        Queue::fake();

        SlowLog::factory()->count(3)->create();

        $this->artisan('slower:analyze', ['--queue' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Queued: 3');

        Queue::assertPushed(AnalyzeSlowLog::class, 3);
        expect(SlowLog::query()->where('is_analyzed', false)->count())->toBe(3);
    });

    it('honors the configured queue name when dispatching', function () {
        config(['slower.analyze_queue' => 'analysis']);
        Queue::fake();

        SlowLog::factory()->create();

        $this->artisan('slower:analyze', ['--queue' => true])->assertSuccessful();

        Queue::assertPushedOn('analysis', AnalyzeSlowLog::class);
    });
});
