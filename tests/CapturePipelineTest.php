<?php

use HalilCosdu\Slower\Events\SlowQueryCaptured;
use HalilCosdu\Slower\Events\SlowQueryFirstSeen;
use HalilCosdu\Slower\Models\SlowLog;
use HalilCosdu\Slower\Services\ExecutionContext;
use HalilCosdu\Slower\Support\SqlFingerprinter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * A model whose persistence always fails — simulates a full disk / dropped
 * table so the capture circuit breaker can be exercised.
 */
class UnstorableSlowLog extends SlowLog
{
    public function save(array $options = [])
    {
        throw new RuntimeException('storage exploded');
    }
}

beforeEach(function () {
    // Every query is "slow" from here on; the package's own queries stay
    // excluded via the table-name guard.
    config(['slower.threshold' => 0]);
});

describe('capture enrichment', function () {
    it('stores fingerprint, version and origin with each capture', function () {
        DB::select('select 1 as probe');

        $record = SlowLog::query()->latest('id')->first();

        expect($record)->not->toBeNull()
            ->and($record->fingerprint)->toBe((new SqlFingerprinter)->fingerprint('select 1 as probe'))
            ->and($record->fingerprint_version)->toBe(SqlFingerprinter::VERSION)
            ->and($record->origin['type'])->toBe('console')
            ->and($record->origin['frame'])->toContain('CapturePipelineTest.php:');
    });

    it('omits origin when disabled', function () {
        config(['slower.capture.origin.enabled' => false]);

        DB::select('select 1 as probe');

        expect(SlowLog::query()->latest('id')->first()->origin)->toBeNull();
    });
});

describe('capture events', function () {
    it('fires Captured for every capture but FirstSeen only for a new fingerprint', function () {
        Event::fake([SlowQueryCaptured::class, SlowQueryFirstSeen::class]);

        DB::select('select 1 as probe');
        DB::select('select 1 as probe');
        DB::select('select 2 as other_shape');

        Event::assertDispatchedTimes(SlowQueryCaptured::class, 3);
        Event::assertDispatchedTimes(SlowQueryFirstSeen::class, 2);
    });

    it('exposes the stored record on the events', function () {
        $seen = null;
        Event::listen(SlowQueryFirstSeen::class, function (SlowQueryFirstSeen $event) use (&$seen) {
            $seen = $event->record;
        });

        DB::select('select 1 as probe');

        expect($seen)->toBeInstanceOf(SlowLog::class)
            ->and($seen->exists)->toBeTrue();
    });

    it('does not treat a shape as first-seen when an earlier row already carries its fingerprint', function () {
        Event::fake([SlowQueryCaptured::class, SlowQueryFirstSeen::class]);

        // e.g. a row backfilled by slower:fingerprint, or captured by another process.
        SlowLog::factory()->create([
            'fingerprint' => (new SqlFingerprinter)->fingerprint('select 1 as probe'),
            'connection_name' => 'testing',
        ]);

        DB::select('select 1 as probe');

        Event::assertDispatched(SlowQueryCaptured::class);
        Event::assertNotDispatched(SlowQueryFirstSeen::class);
    });

    it('treats a known shape on a new connection as first-seen (matches grouped view scoping)', function () {
        Event::fake([SlowQueryFirstSeen::class]);

        // Same shape already seen, but on a different connection.
        SlowLog::factory()->create([
            'fingerprint' => (new SqlFingerprinter)->fingerprint('select 1 as probe'),
            'connection_name' => 'other-connection',
        ]);

        DB::select('select 1 as probe'); // captured on the 'testing' connection

        Event::assertDispatched(SlowQueryFirstSeen::class);
    });

    it('still fires FirstSeen when a Captured listener throws (events are independent)', function () {
        Event::listen(SlowQueryCaptured::class, function () {
            throw new RuntimeException('captured listener down');
        });
        $firstSeen = 0;
        Event::listen(SlowQueryFirstSeen::class, function () use (&$firstSeen) {
            $firstSeen++;
        });

        DB::select('select 1 as brand_new_shape');

        expect($firstSeen)->toBe(1)
            ->and(SlowLog::query()->count())->toBe(1);
    });

    it('keeps the row and still fires events when a synchronous listener throws', function () {
        // A user listener (e.g. a Slack notifier) throwing must NOT be mistaken
        // for a storage failure: the row is already stored, so the circuit
        // breaker must stay closed and capture must keep working.
        Event::listen(SlowQueryCaptured::class, function () {
            throw new RuntimeException('slack webhook down');
        });

        DB::select('select 1 as probe');

        expect(SlowLog::query()->count())->toBe(1)
            ->and(app(ExecutionContext::class)->isSuspended())->toBeFalse();

        // A second capture still lands (breaker never opened).
        DB::select('select 2 as other');
        expect(SlowLog::query()->count())->toBe(2);
    });
});

describe('sampling and caps', function () {
    it('captures nothing at sample_rate zero', function () {
        config(['slower.capture.sample_rate' => 0]);

        DB::select('select 1 as probe');
        DB::select('select 2 as probe');

        expect(SlowLog::query()->count())->toBe(0);
    });

    it('stops capturing after max_per_execution is reached', function () {
        config(['slower.capture.max_per_execution' => 2]);

        DB::select('select 1 as a');
        DB::select('select 2 as b');
        DB::select('select 3 as c');

        expect(SlowLog::query()->count())->toBe(2);
    });

    it('treats a null max_per_execution as unlimited', function () {
        config(['slower.capture.max_per_execution' => null]);

        foreach (range(1, 60) as $i) {
            DB::select('select '.$i.' as col_a');
        }

        expect(SlowLog::query()->count())->toBe(60);
    });
});

describe('capture circuit breaker', function () {
    it('suspends captures after a storage failure instead of failing every query', function () {
        config(['slower.resources.model' => UnstorableSlowLog::class]);

        DB::select('select 1 as probe'); // fails to store -> breaker opens

        expect(app(ExecutionContext::class)->isSuspended())->toBeTrue();

        // Storage is healthy again, but the breaker is still open.
        config(['slower.resources.model' => SlowLog::class]);

        DB::select('select 2 as probe');

        expect(SlowLog::query()->count())->toBe(0);
    });
});

describe('self-capture guard', function () {
    it('never captures queries that touch its own table', function () {
        DB::select('select count(*) as c from '.config('slower.resources.table_name'));

        expect(SlowLog::query()->count())->toBe(0);
    });
});
