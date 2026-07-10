<?php

use HalilCosdu\Slower\Models\SlowLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    Gate::define('viewSlower', fn ($user = null) => true);
});

describe('dashboard index', function () {
    it('shows an empty state when nothing has been captured', function () {
        $this->get(route('slower.index'))
            ->assertOk()
            ->assertSeeText('No slow queries captured yet');
    });

    it('exposes correct stats for the captured queries', function () {
        SlowLog::factory()->create(['time' => 15000, 'is_analyzed' => true]);
        SlowLog::factory()->create(['time' => 20000]);
        SlowLog::factory()->create(['time' => 25000]);

        $this->get(route('slower.index'))
            ->assertOk()
            ->assertViewHas('stats', fn (array $stats) => $stats['total'] === 3
                && $stats['pending'] === 2
                && (int) $stats['avg_time'] === 20000
                && (int) $stats['max_time'] === 25000);
    });

    it('lists captured queries newest first', function () {
        $old = SlowLog::factory()->create(['raw_sql' => 'select * from old_table']);
        $new = SlowLog::factory()->create(['raw_sql' => 'select * from new_table']);

        $this->get(route('slower.index'))
            ->assertOk()
            ->assertSeeText('new_table')
            ->assertViewHas('records', fn (LengthAwarePaginator $records) => $records->first()->id === $new->id);
    });

    it('filters by free-text search on the raw sql', function () {
        SlowLog::factory()->create(['raw_sql' => 'select * from product_prices where price = 0']);
        SlowLog::factory()->create(['raw_sql' => 'select * from users where id = 1']);

        $this->get(route('slower.index', ['search' => 'product_prices']))
            ->assertOk()
            ->assertViewHas('records', fn (LengthAwarePaginator $records) => $records->total() === 1
                && str_contains($records->first()->raw_sql, 'product_prices'));
    });

    it('treats like wildcards in search literally', function () {
        SlowLog::factory()->create(['raw_sql' => 'select * from users where name = "a%b"']);
        SlowLog::factory()->create(['raw_sql' => 'select * from users where name = "ab"']);

        $this->get(route('slower.index', ['search' => 'a%b']))
            ->assertOk()
            ->assertViewHas('records', fn (LengthAwarePaginator $records) => $records->total() === 1);
    });

    it('filters by analyzed status', function () {
        SlowLog::factory()->create(['is_analyzed' => true, 'raw_sql' => 'select 1 from analyzed_stuff']);
        SlowLog::factory()->create(['is_analyzed' => false, 'raw_sql' => 'select 1 from pending_stuff']);

        $this->get(route('slower.index', ['status' => 'pending']))
            ->assertViewHas('records', fn (LengthAwarePaginator $records) => $records->total() === 1
                && $records->first()->is_analyzed === false);

        $this->get(route('slower.index', ['status' => 'analyzed']))
            ->assertViewHas('records', fn (LengthAwarePaginator $records) => $records->total() === 1
                && $records->first()->is_analyzed === true);
    });

    it('filters by connection name and lists distinct connections', function () {
        SlowLog::factory()->create(['connection_name' => 'mysql']);
        SlowLog::factory()->create(['connection_name' => 'pgsql']);
        SlowLog::factory()->create(['connection_name' => 'pgsql']);

        $this->get(route('slower.index', ['connection' => 'mysql']))
            ->assertViewHas('records', fn (LengthAwarePaginator $records) => $records->total() === 1
                && $records->first()->connection_name === 'mysql')
            ->assertViewHas('connections', fn ($connections) => $connections->values()->all() === ['mysql', 'pgsql']);
    });

    it('sorts by time in both directions through a whitelist', function () {
        $fast = SlowLog::factory()->create(['time' => 11000]);
        $slow = SlowLog::factory()->create(['time' => 99000]);

        $this->get(route('slower.index', ['sort' => 'time', 'direction' => 'asc']))
            ->assertViewHas('records', fn (LengthAwarePaginator $records) => $records->first()->id === $fast->id);

        $this->get(route('slower.index', ['sort' => 'time', 'direction' => 'desc']))
            ->assertViewHas('records', fn (LengthAwarePaginator $records) => $records->first()->id === $slow->id);
    });

    it('falls back to the default order for unknown sort columns', function () {
        SlowLog::factory()->create();
        $newest = SlowLog::factory()->create();

        $this->get(route('slower.index', ['sort' => 'evil;drop table', 'direction' => 'up']))
            ->assertOk()
            ->assertViewHas('records', fn (LengthAwarePaginator $records) => $records->first()->id === $newest->id);
    });

    it('paginates and keeps the query string on page links', function () {
        SlowLog::factory()->count(30)->create(['raw_sql' => 'select * from paged_table']);

        $response = $this->get(route('slower.index', ['search' => 'paged_table']));

        $response->assertViewHas('records', fn (LengthAwarePaginator $records) => $records->count() === 25
            && $records->total() === 30);
        $response->assertSee('search=paged_table', false);

        $this->get(route('slower.index', ['search' => 'paged_table', 'page' => 2]))
            ->assertViewHas('records', fn (LengthAwarePaginator $records) => $records->count() === 5);
    });

    it('truncates long sql in the list but keeps it complete on the detail page', function () {
        $record = SlowLog::factory()->create([
            'raw_sql' => 'select * from products where description = "'.str_repeat('x', 200).'ZZZTAIL"',
        ]);

        $this->get(route('slower.index'))
            ->assertOk()
            ->assertDontSeeText('ZZZTAIL');

        $this->get(route('slower.show', $record))
            ->assertOk()
            ->assertSeeText('ZZZTAIL');
    });

    it('escapes captured sql in the list', function () {
        SlowLog::factory()->create(['raw_sql' => 'select "<script>alert(1)</script>" from users']);

        $this->get(route('slower.index'))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    });
});
