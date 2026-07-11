<?php

use HalilCosdu\Slower\Models\SlowLog;
use HalilCosdu\Slower\Support\SqlFingerprinter;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    Gate::define('viewSlower', fn ($user = null) => true);
});

function seedGroup(string $sql, int $count, array $overrides = []): void
{
    SlowLog::factory()->count($count)->create(array_merge([
        'sql' => $sql,
        'raw_sql' => $sql,
        'fingerprint' => (new SqlFingerprinter)->fingerprint($sql),
        'fingerprint_version' => SqlFingerprinter::VERSION,
    ], $overrides));
}

describe('grouped view', function () {
    it('shows one row per query shape with its occurrence count', function () {
        seedGroup('select * from orders where status = ?', 3, ['time' => 12000]);
        seedGroup('select * from users where id = ?', 1, ['time' => 30000]);

        $response = $this->get(route('slower.index', ['view' => 'grouped']))->assertOk();

        $groups = $response->viewData('groups');

        expect($groups)->toHaveCount(2)
            ->and((int) $groups->firstWhere('fingerprint', (new SqlFingerprinter)->fingerprint('select * from orders where status = ?'))->occurrences)->toBe(3);
    });

    it('orders groups by occurrences by default', function () {
        seedGroup('select * from rare_shape', 1);
        seedGroup('select * from frequent_shape', 4);

        $groups = $this->get(route('slower.index', ['view' => 'grouped']))->viewData('groups');

        expect($groups->first()->occurrences)->toBe(4);
    });

    it('aggregates avg and max duration per group', function () {
        seedGroup('select * from orders', 1, ['time' => 10000]);
        seedGroup('select * from orders', 1, ['time' => 30000]);

        $group = $this->get(route('slower.index', ['view' => 'grouped']))->viewData('groups')->first();

        expect((float) $group->max_time)->toBe(30000.0)
            ->and((float) $group->avg_time)->toBe(20000.0);
    });

    it('separates the same query shape per connection', function () {
        seedGroup('select * from orders', 2, ['connection_name' => 'mysql']);
        seedGroup('select * from orders', 1, ['connection_name' => 'replica']);

        $groups = $this->get(route('slower.index', ['view' => 'grouped']))->viewData('groups');

        expect($groups)->toHaveCount(2);
    });

    it('links a group to its filtered events', function () {
        seedGroup('select * from orders where id = ?', 2);
        seedGroup('select * from users where id = ?', 1);

        $fingerprint = (new SqlFingerprinter)->fingerprint('select * from orders where id = ?');

        $records = $this->get(route('slower.index', ['fingerprint' => $fingerprint]))
            ->assertOk()
            ->viewData('records');

        expect($records)->toHaveCount(2);
    });

    it('hints at the backfill command when legacy rows exist', function () {
        SlowLog::factory()->create(['fingerprint' => null, 'fingerprint_version' => null]);

        $this->get(route('slower.index', ['view' => 'grouped']))
            ->assertSee('slower:fingerprint');
    });

    it('keeps the events view as the default (backward compatible)', function () {
        SlowLog::factory()->create();

        $response = $this->get(route('slower.index'))->assertOk();

        expect($response->viewData('view'))->toBe('events');
    });

    it('applies the status filter before aggregation', function () {
        seedGroup('select * from orders', 2, ['is_analyzed' => true]);
        seedGroup('select * from orders', 1, ['is_analyzed' => false]);

        $groups = $this->get(route('slower.index', ['view' => 'grouped', 'status' => 'pending']))->viewData('groups');

        expect($groups)->toHaveCount(1)
            ->and((int) $groups->first()->occurrences)->toBe(1);
    });
});

describe('origin on the detail page', function () {
    it('renders the origin panel when the record carries one', function () {
        $record = SlowLog::factory()->create([
            'origin' => [
                'type' => 'http',
                'route' => 'orders.index',
                'action' => 'App\Http\Controllers\OrderController@index',
                'frame' => 'app/Http/Controllers/OrderController.php:42',
            ],
        ]);

        $this->get(route('slower.show', $record))
            ->assertOk()
            ->assertSee('orders.index')
            ->assertSee('OrderController.php:42');
    });

    it('omits the origin panel for legacy records', function () {
        $record = SlowLog::factory()->create(['origin' => null]);

        $this->get(route('slower.show', $record))
            ->assertOk()
            ->assertDontSee('<h2>Origin</h2>', false);
    });
});
