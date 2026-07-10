<?php

use HalilCosdu\Slower\Models\SlowLog;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    Gate::define('viewSlower', fn ($user = null) => true);
});

describe('dashboard detail page', function () {
    it('renders the full query with bindings and metadata', function () {
        $record = SlowLog::factory()->create([
            'raw_sql' => 'select count(*) as aggregate from product_prices where product_id = 1',
            'sql' => 'select count(*) as aggregate from product_prices where product_id = ?',
            'bindings' => [1],
            'time' => 12345.6,
            'connection_name' => 'mysql',
        ]);

        $this->get(route('slower.show', $record))
            ->assertOk()
            ->assertSeeText('select count(*) as aggregate from product_prices')
            ->assertSeeText('mysql')
            ->assertViewHas('record', fn ($r) => $r->is($record));
    });

    it('shows a pending callout instead of a recommendation when not analyzed', function () {
        $record = SlowLog::factory()->create(['is_analyzed' => false, 'recommendation' => null]);

        $this->get(route('slower.show', $record))
            ->assertOk()
            ->assertSeeText('This query has not been analyzed yet');
    });

    it('renders the AI recommendation from markdown into safe html', function () {
        $record = SlowLog::factory()->create([
            'is_analyzed' => true,
            'recommendation' => "## Indexing\n\nAdd a **composite** index:\n\n```sql\nCREATE INDEX idx ON t (a, b);\n```",
        ]);

        $this->get(route('slower.show', $record))
            ->assertOk()
            ->assertSee('<h4>Indexing</h4>', false)
            ->assertSee('<strong>composite</strong>', false)
            ->assertSee('<pre><code>CREATE INDEX idx ON t (a, b);</code></pre>', false);
    });

    it('formats the raw sql with line breaks before keywords', function () {
        $record = SlowLog::factory()->create([
            'raw_sql' => 'select count(*) as aggregate from product_prices where product_id = 1',
        ]);

        $this->get(route('slower.show', $record))
            ->assertOk()
            ->assertSee("select count(*) as aggregate\nfrom product_prices\nwhere product_id = 1", false);
    });

    it('returns 404 for unknown records', function () {
        $this->get(route('slower.show', 999999))->assertNotFound();
    });

    it('returns 404 for non-numeric record ids', function () {
        $this->get('/slower/not-a-number')->assertNotFound();
    });

    it('escapes hostile sql, bindings and recommendations', function () {
        $payload = '<script>alert(1)</script>';
        $record = SlowLog::factory()->create([
            'raw_sql' => 'select "'.$payload.'" from users',
            'sql' => 'select "'.$payload.'" from users where id = ?',
            'bindings' => [$payload],
            'is_analyzed' => true,
            'recommendation' => 'Use an index. '.$payload,
        ]);

        $this->get(route('slower.show', $record))
            ->assertOk()
            ->assertDontSee($payload, false)
            ->assertSee('&lt;script&gt;', false);
    });
});
