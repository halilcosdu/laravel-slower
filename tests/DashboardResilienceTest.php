<?php

use HalilCosdu\Slower\AiServiceDrivers\Contracts\AiServiceDriver;
use HalilCosdu\Slower\Models\SlowLog;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * A cache store that does not implement LockProvider, so lock() throws —
 * the same behavior as Laravel's `apc` store.
 */
class LocklessTestStore implements Store
{
    private array $items = [];

    public function get($key)
    {
        return $this->items[$key] ?? null;
    }

    public function many(array $keys)
    {
        return array_map(fn ($key) => $this->get($key), array_combine($keys, $keys));
    }

    public function put($key, $value, $seconds)
    {
        $this->items[$key] = $value;

        return true;
    }

    public function putMany(array $values, $seconds)
    {
        foreach ($values as $key => $value) {
            $this->items[$key] = $value;
        }

        return true;
    }

    public function increment($key, $value = 1)
    {
        return $this->items[$key] = ($this->items[$key] ?? 0) + $value;
    }

    public function decrement($key, $value = 1)
    {
        return $this->increment($key, -$value);
    }

    public function forever($key, $value)
    {
        return $this->put($key, $value, 0);
    }

    // Present on the Laravel 13 Store contract; harmless as an extra method on 11/12.
    public function touch($key, $seconds)
    {
        return true;
    }

    public function forget($key)
    {
        unset($this->items[$key]);

        return true;
    }

    public function flush()
    {
        $this->items = [];

        return true;
    }

    public function getPrefix()
    {
        return '';
    }
}

beforeEach(function () {
    Gate::define('viewSlower', fn ($user = null) => true);
    // Simulate a slow-query-only install: package enabled, but no AI provider
    // configured. Resolving the OpenAI driver would throw here.
    config(['slower.open_ai.api_key' => null]);
});

describe('dashboard without a configured AI provider', function () {
    it('renders the index without resolving the AI driver', function () {
        SlowLog::factory()->count(2)->create();

        $this->get(route('slower.index'))->assertOk();
    });

    it('renders a detail page without resolving the AI driver', function () {
        $record = SlowLog::factory()->create();

        $this->get(route('slower.show', $record))->assertOk();
    });

    it('deletes a record without resolving the AI driver', function () {
        $record = SlowLog::factory()->create();

        $this->delete(route('slower.destroy', $record))->assertRedirect();

        expect(SlowLog::find($record->id))->toBeNull();
    });

    it('cleans records without resolving the AI driver', function () {
        SlowLog::factory()->create(['created_at' => now()->subDays(30)]);

        $this->delete(route('slower.clean'), ['days' => 15])
            ->assertRedirect()
            ->assertSessionHas('slower.status');
    });

    it('flashes an error instead of 500ing when analyze is triggered but the driver cannot resolve', function () {
        $record = SlowLog::factory()->create();

        $this->post(route('slower.analyze', $record))
            ->assertRedirect()
            ->assertSessionHas('slower.error');

        expect($record->refresh()->is_analyzed)->toBeFalse();
    });
});

describe('analyze when the cache store cannot lock', function () {
    it('flashes an error instead of 500ing', function () {
        config(['slower.open_ai.api_key' => 'test-key']);
        $ai = Mockery::mock(AiServiceDriver::class);
        $ai->shouldReceive('analyze')->never();
        app()->instance(AiServiceDriver::class, $ai);

        // A store that does not implement LockProvider: Repository::lock()
        // throws BadMethodCallException, exactly like the `apc` store.
        Cache::extend('lockless', fn () => Cache::repository(new LocklessTestStore));
        config(['cache.stores.lockless' => ['driver' => 'lockless'], 'cache.default' => 'lockless']);

        $record = SlowLog::factory()->create();

        $this->post(route('slower.analyze', $record))
            ->assertRedirect()
            ->assertSessionHas('slower.error');

        expect($record->refresh()->is_analyzed)->toBeFalse();
    });
});
