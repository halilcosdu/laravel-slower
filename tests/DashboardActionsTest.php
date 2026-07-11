<?php

use HalilCosdu\Slower\AiServiceDrivers\Contracts\AiServiceDriver;
use HalilCosdu\Slower\Models\SlowLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Mockery\MockInterface;

beforeEach(function () {
    Gate::define('viewSlower', fn ($user = null) => true);
});

function fakeAi(?string $result = 'Add an index on (id).'): MockInterface
{
    $ai = Mockery::mock(AiServiceDriver::class);
    if ($result !== null) {
        $ai->shouldReceive('analyze')->andReturn($result);
    }
    app()->instance(AiServiceDriver::class, $ai);

    return $ai;
}

describe('analyze one', function () {
    it('analyzes a pending record and redirects back with a success flash', function () {
        fakeAi('Add a **composite** index.');
        $record = SlowLog::factory()->create([
            'raw_sql' => 'select * from '.config('slower.resources.table_name'),
        ]);

        $this->from(route('slower.show', $record))
            ->post(route('slower.analyze', $record))
            ->assertRedirect(route('slower.show', $record))
            ->assertSessionHas('slower.status');

        expect($record->refresh())
            ->is_analyzed->toBeTrue()
            ->recommendation->toBe('Add a **composite** index.');
    });

    it('keeps the record retryable and flashes an error when the AI returns nothing', function () {
        $ai = Mockery::mock(AiServiceDriver::class);
        $ai->shouldReceive('analyze')->once()->andReturn(null);
        app()->instance(AiServiceDriver::class, $ai);

        $record = SlowLog::factory()->create([
            'raw_sql' => 'select * from '.config('slower.resources.table_name'),
        ]);

        $this->post(route('slower.analyze', $record))
            ->assertRedirect()
            ->assertSessionHas('slower.error');

        expect($record->refresh()->is_analyzed)->toBeFalse();
    });

    it('flashes a generic error and stays retryable when the AI driver throws', function () {
        $ai = Mockery::mock(AiServiceDriver::class);
        $ai->shouldReceive('analyze')->once()->andThrow(new RuntimeException('boom secret-key leaked'));
        app()->instance(AiServiceDriver::class, $ai);

        $record = SlowLog::factory()->create([
            'raw_sql' => 'select * from '.config('slower.resources.table_name'),
        ]);

        $response = $this->post(route('slower.analyze', $record))
            ->assertRedirect()
            ->assertSessionHas('slower.error');

        expect(session('slower.error'))->not->toContain('secret-key');
        expect($record->refresh()->is_analyzed)->toBeFalse();
    });

    it('rejects analysis when ai_recommendation is disabled', function () {
        config(['slower.ai_recommendation' => false]);
        $ai = Mockery::mock(AiServiceDriver::class);
        $ai->shouldReceive('analyze')->never();
        app()->instance(AiServiceDriver::class, $ai);

        $record = SlowLog::factory()->create();

        $this->post(route('slower.analyze', $record))
            ->assertRedirect()
            ->assertSessionHas('slower.error');

        expect($record->refresh()->is_analyzed)->toBeFalse();
    });

    it('re-analyzes an already analyzed record on request', function () {
        fakeAi('A fresh recommendation.');
        $record = SlowLog::factory()->create([
            'is_analyzed' => true,
            'recommendation' => 'The old recommendation.',
            'raw_sql' => 'select * from '.config('slower.resources.table_name'),
        ]);

        $this->post(route('slower.analyze', $record))->assertRedirect();

        expect($record->refresh()->recommendation)->toBe('A fresh recommendation.');
    });

    it('refuses concurrent analysis of the same record', function () {
        $ai = Mockery::mock(AiServiceDriver::class);
        $ai->shouldReceive('analyze')->never();
        app()->instance(AiServiceDriver::class, $ai);

        $record = SlowLog::factory()->create();

        $lock = Cache::lock('slower:analyze:'.$record->id, 30);
        expect($lock->get())->toBeTrue();

        try {
            $this->post(route('slower.analyze', $record))
                ->assertRedirect()
                ->assertSessionHas('slower.error');
        } finally {
            $lock->release();
        }

        expect($record->refresh()->is_analyzed)->toBeFalse();
    });

    it('rate limits analysis requests', function () {
        $ai = Mockery::mock(AiServiceDriver::class);
        $ai->shouldReceive('analyze')->times(5)->andReturn('ok');
        app()->instance(AiServiceDriver::class, $ai);

        $record = SlowLog::factory()->create([
            'raw_sql' => 'select * from '.config('slower.resources.table_name'),
        ]);

        foreach (range(1, 5) as $i) {
            $this->post(route('slower.analyze', $record))->assertSessionHas('slower.status');
        }

        $this->post(route('slower.analyze', $record))
            ->assertRedirect()
            ->assertSessionHas('slower.error');
    });

    it('flashes an error and stays retryable when the record connection cannot be resolved', function () {
        $ai = Mockery::mock(AiServiceDriver::class);
        $ai->shouldReceive('analyze')->never();
        app()->instance(AiServiceDriver::class, $ai);

        // A connection that is no longer configured makes schema extraction
        // throw; the dashboard must surface it without finalizing the record.
        $record = SlowLog::factory()->create(['connection_name' => 'does-not-exist']);

        $this->post(route('slower.analyze', $record))
            ->assertRedirect()
            ->assertSessionHas('slower.error');

        expect($record->refresh()->is_analyzed)->toBeFalse();
    });

    it('returns 404 when analyzing an unknown record', function () {
        fakeAi();

        $this->post(route('slower.analyze', ['log' => 999999]))->assertNotFound();
    });
});

describe('analyze pending', function () {
    it('processes at most the configured limit and reports the remainder', function () {
        config(['slower.dashboard.analyze_pending_limit' => 3]);

        $ai = Mockery::mock(AiServiceDriver::class);
        $ai->shouldReceive('analyze')->times(3)->andReturn('ok');
        app()->instance(AiServiceDriver::class, $ai);

        SlowLog::factory()->count(5)->create([
            'raw_sql' => 'select * from '.config('slower.resources.table_name'),
        ]);

        $this->post(route('slower.analyze-pending'))
            ->assertRedirect()
            ->assertSessionHas('slower.status');

        expect(SlowLog::where('is_analyzed', true)->count())->toBe(3)
            ->and(SlowLog::where('is_analyzed', false)->count())->toBe(2)
            ->and(session('slower.status'))->toContain('2');
    });

    it('flashes a notice when there is nothing to analyze', function () {
        fakeAi();

        $this->post(route('slower.analyze-pending'))
            ->assertRedirect()
            ->assertSessionHas('slower.status');
    });

    it('flashes a configuration error when none of the pending queries can be analyzed', function () {
        $ai = Mockery::mock(AiServiceDriver::class);
        $ai->shouldReceive('analyze')->andReturn(null); // provider unusable for every record
        app()->instance(AiServiceDriver::class, $ai);

        SlowLog::factory()->count(2)->create([
            'raw_sql' => 'select * from '.config('slower.resources.table_name'),
        ]);

        $this->post(route('slower.analyze-pending'))
            ->assertRedirect()
            ->assertSessionHas('slower.error');

        expect(SlowLog::where('is_analyzed', false)->count())->toBe(2);
    });

    it('shares the analysis rate limit with single-record analysis', function () {
        $ai = Mockery::mock(AiServiceDriver::class);
        $ai->shouldReceive('analyze')->times(5)->andReturn('ok');
        app()->instance(AiServiceDriver::class, $ai);

        $record = SlowLog::factory()->create([
            'raw_sql' => 'select * from '.config('slower.resources.table_name'),
        ]);

        foreach (range(1, 5) as $i) {
            $this->post(route('slower.analyze', $record));
        }

        SlowLog::factory()->create();

        $this->post(route('slower.analyze-pending'))
            ->assertRedirect()
            ->assertSessionHas('slower.error');
    });
});

describe('destroy', function () {
    it('deletes exactly one record', function () {
        $doomed = SlowLog::factory()->create();
        $kept = SlowLog::factory()->create();

        $this->delete(route('slower.destroy', $doomed))
            ->assertRedirect(route('slower.index'))
            ->assertSessionHas('slower.status');

        expect(SlowLog::find($doomed->id))->toBeNull()
            ->and(SlowLog::find($kept->id))->not->toBeNull();
    });

    it('returns 404 for unknown records', function () {
        $this->delete(route('slower.destroy', ['log' => 999999]))->assertNotFound();
    });

    it('does not change state via GET', function () {
        $record = SlowLog::factory()->create();

        $this->get('/slower/'.$record->id.'/analyze')->assertStatus(405);

        expect(SlowLog::find($record->id))->not->toBeNull();
    });
});

describe('clean', function () {
    it('deletes only records older than the given number of days', function () {
        $old = SlowLog::factory()->create(['created_at' => now()->subDays(30)]);
        $recent = SlowLog::factory()->create(['created_at' => now()->subDays(2)]);

        $this->delete(route('slower.clean'), ['days' => 15])
            ->assertRedirect()
            ->assertSessionHas('slower.status');

        expect(SlowLog::find($old->id))->toBeNull()
            ->and(SlowLog::find($recent->id))->not->toBeNull()
            ->and(session('slower.status'))->toContain('1');
    });

    it('deletes everything when days is zero', function () {
        SlowLog::factory()->count(3)->create(['created_at' => now()->subMinutes(5)]);

        $this->delete(route('slower.clean'), ['days' => 0])->assertRedirect();

        expect(SlowLog::count())->toBe(0);
    });

    it('rejects invalid day values', function () {
        SlowLog::factory()->create(['created_at' => now()->subDays(30)]);

        $this->from(route('slower.index'))->delete(route('slower.clean'), ['days' => -1])->assertSessionHasErrors('days');
        $this->from(route('slower.index'))->delete(route('slower.clean'), ['days' => 'soon'])->assertSessionHasErrors('days');
        $this->from(route('slower.index'))->delete(route('slower.clean'), ['days' => 9999])->assertSessionHasErrors('days');
        $this->from(route('slower.index'))->delete(route('slower.clean'), [])->assertSessionHasErrors('days');

        expect(SlowLog::count())->toBe(1);
    });
});
