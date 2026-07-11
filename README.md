<div align="center">

# Laravel Slower

**Find your slowest database queries — and let AI tell you how to fix them.**

<a href="https://trendshift.io/repositories/10023?utm_source=trendshift-badge&amp;utm_medium=badge&amp;utm_campaign=badge-trendshift-10023" target="_blank" rel="noopener noreferrer"><img src="https://trendshift.io/api/badge/trendshift/repositories/10023/daily?language=PHP" alt="halilcosdu%2Flaravel-slower | Trendshift" width="250" height="55"/></a>

[![Latest Version on Packagist](https://img.shields.io/packagist/v/halilcosdu/laravel-slower.svg?style=flat-square)](https://packagist.org/packages/halilcosdu/laravel-slower)
[![Total Downloads](https://img.shields.io/packagist/dt/halilcosdu/laravel-slower.svg?style=flat-square)](https://packagist.org/packages/halilcosdu/laravel-slower)

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="art/dashboard-dark.png">
  <img src="art/dashboard-light.png" alt="The Laravel Slower dashboard listing slow queries with duration bars, filters and AI analysis status" width="900">
</picture>

</div>

Laravel Slower watches every query your application runs, captures the ones that cross your threshold, and uses AI to recommend indexes and query rewrites. Since **v2.3** it ships with a **built-in dashboard** — install the package and you have a full slow-query UI at `/slower`, with zero frontend work: no npm, no CDN, no assets to publish.

## Features

- 🚨 **Automatic capture** — a `DB::listen` hook logs every query slower than your threshold, with bindings and resolved SQL.
- 🤖 **AI recommendations** — sends the query, schema, indexes and a safe `EXPLAIN` plan to your AI provider (OpenAI out of the box, any provider via a driver) and stores actionable optimization advice.
- 📊 **Built-in dashboard** — stats, search, filters, sorting, query detail with rendered recommendations, one-click *Analyze with AI*, cleanup tools. Dark and light theme, fully self-contained.
- 🛡️ **Safe by default** — the dashboard is only accessible in the `local` environment until you explicitly open it, AI actions are rate-limited and capped, destructive actions ask first.
- ⏰ **Scheduler-friendly commands** — `slower:analyze` and `slower:clean` for bulk analysis and retention.

## Requirements

- PHP 8.3+
- Laravel 11.x, 12.x or 13.x

## Installation

```bash
composer require halilcosdu/laravel-slower

php artisan vendor:publish --tag="slower-migrations"
php artisan migrate
```

That's it — in your local environment, open **`/slower`** and every captured slow query is waiting for you.

Optionally publish the config file:

```bash
php artisan vendor:publish --tag="slower-config"
```

## The Dashboard

<div align="center">
<img src="art/detail-dark.png" alt="Query detail page showing the formatted SQL, bindings and a rendered AI recommendation" width="900">
</div>

The dashboard lives at `/slower` and gives you:

- **Overview stats** — captured count, pending analysis, average and max duration.
- **The query list** — duration bars, search over the resolved SQL, status and connection filters, sortable columns, pagination.
- **Query detail** — formatted SQL, parameterized statement, bindings, and the AI recommendation rendered from markdown.
- **Actions** — *Analyze with AI* per query (or up to `analyze_pending_limit` pending queries at once), delete, and *clean up older than N days* (`0` wipes everything). Every AI action warns that it may incur provider charges; every destructive action asks for confirmation.

### Authorizing access in production

Exactly like Telescope and Horizon, the dashboard is protected by a gate. By default it only allows access in the `local` environment. To open it up in other environments, define a `viewSlower` gate — for example in `AppServiceProvider`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewSlower', function ($user = null) {
    return $user?->email === 'you@example.com';
});
```

### Dashboard configuration

```php
'dashboard' => [
    'enabled' => env('SLOWER_DASHBOARD_ENABLED', true),
    'path' => env('SLOWER_DASHBOARD_PATH', 'slower'),
    'domain' => env('SLOWER_DASHBOARD_DOMAIN'),
    'middleware' => [
        'web',
        HalilCosdu\Slower\Http\Middleware\Authorize::class,
    ],
    'per_page' => 25,
    'analyze_pending_limit' => 10,
],
```

Set `SLOWER_DASHBOARD_ENABLED=false` to remove the routes entirely, or change `path` to serve it elsewhere. Want to restyle it? `php artisan vendor:publish --tag="laravel-slower-views"`.

> [!WARNING]
> Captured SQL and bindings can contain user data, tokens and other secrets, and analyzing a query sends it (with schema context) to your AI provider as a billable API call. Keep the gate tight, and prune regularly with `slower:clean`.

## Configuration

This is the full contents of the published config file:

```php
use HalilCosdu\Slower\Http\Middleware\Authorize;
use HalilCosdu\Slower\Models\SlowLog;

return [
    'enabled' => env('SLOWER_ENABLED', true),
    'threshold' => env('SLOWER_THRESHOLD', 10000), // ms
    'ai_service' => env('SLOWER_AI_SERVICE', 'openai'),
    'resources' => [
        'table_name' => (new SlowLog)->getTable(),
        'model' => SlowLog::class,
    ],
    'dashboard' => [
        'enabled' => env('SLOWER_DASHBOARD_ENABLED', true),
        'path' => env('SLOWER_DASHBOARD_PATH', 'slower'),
        'domain' => env('SLOWER_DASHBOARD_DOMAIN'),
        'middleware' => [
            'web',
            Authorize::class,
        ],
        'per_page' => 25,
        'analyze_pending_limit' => 10,
    ],
    'ai_recommendation' => env('SLOWER_AI_RECOMMENDATION', true),
    // null → a sensible low-cost default for the selected provider
    'recommendation_model' => env('SLOWER_AI_RECOMMENDATION_MODEL'),
    'recommendation_use_explain' => env('SLOWER_AI_RECOMMENDATION_USE_EXPLAIN', true),
    'ignore_explain_queries' => env('SLOWER_IGNORE_EXPLAIN_QUERIES', true),
    'ignore_insert_queries' => env('SLOWER_IGNORE_INSERT_QUERIES', true),
    'prompt' => env('SLOWER_PROMPT', '...'), // the system prompt sent to the AI
];
```

Disable AI recommendations by setting `ai_recommendation` to `false` — the package will keep logging slow queries but never call an AI API.

### AI providers

Slower talks to every major LLM through [Prism](https://prismphp.com) — one official package, all providers, and **no provider credentials in Slower's own config**. Pick a provider with a single variable:

```dotenv
SLOWER_AI_SERVICE=openai      # or: anthropic, gemini, ollama, ...
```

Provider keys live in Prism's config (`config/prism.php`), read from the conventional env vars — set the one for your provider:

```dotenv
OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...
GEMINI_API_KEY=...
```

| `ai_service` | Default model (override with `SLOWER_AI_RECOMMENDATION_MODEL`) |
|---|---|
| `openai` (default) | `gpt-5.4-mini` |
| `anthropic` | `claude-haiku-4-5` |
| `gemini` | `gemini-2.5-flash` |

> Model ids move fast — set `SLOWER_AI_RECOMMENDATION_MODEL` to the current low-cost model for your provider if the default drifts.

**Custom / self-hosted LLMs.** Anything with an OpenAI-compatible endpoint (Ollama, Azure OpenAI, LM Studio, OpenRouter, …) works by pointing Prism's provider at it in `config/prism.php` and setting `SLOWER_AI_SERVICE` to that provider (e.g. `ollama`). For a fully bespoke backend, register a driver in a service provider — no HTTP code required from Slower:

```php
use HalilCosdu\Slower\AiServiceDrivers\AiServiceManager;
use HalilCosdu\Slower\AiServiceDrivers\Contracts\AiServiceDriver;

app(AiServiceManager::class)->extend('my-llm', fn () => new class implements AiServiceDriver
{
    public function analyze(string $userMessage): ?string
    {
        // call your model, return the recommendation text (or null)
    }
});
```

Then set `SLOWER_AI_SERVICE=my-llm`.

## Commands & scheduling

```bash
php artisan slower:analyze      # analyze every record where is_analyzed=false
php artisan slower:clean 15     # delete records older than 15 days
```

```php
use HalilCosdu\Slower\Commands\AnalyzeQuery;
use HalilCosdu\Slower\Commands\SlowLogCleaner;

protected function schedule(Schedule $schedule): void
{
    $schedule->command(AnalyzeQuery::class)->runInBackground()->daily();
    $schedule->command(SlowLogCleaner::class)->runInBackground()->daily();
}
```

## Programmatic usage

```php
$record = \HalilCosdu\Slower\Models\SlowLog::first();

\HalilCosdu\Slower\Facades\Slower::analyze($record); // returns the analyzed model

$record->raw_sql;        // select count(*) as aggregate from "product_prices" where ...
$record->recommendation; // the AI's optimization advice (markdown)
```

<details>
<summary><strong>Example recommendation</strong></summary>

1. Indexing: consider adding a composite index on `product_id`, `price`, and `discount_total`:

```sql
CREATE INDEX idx_product_prices
ON product_prices (product_id, price, discount_total);
```

2. Data types: remove the quotes around numeric comparisons so the index can actually be used:

```sql
SELECT COUNT(*) AS aggregate
FROM product_prices
WHERE product_id = 1 AND price = 0 AND discount_total > 0;
```

3. Statistics: run `ANALYZE product_prices;` so the query planner has fresh statistics to work with.

</details>

## Development & testing

```bash
composer test       # Pest test suite
composer analyse    # PHPStan level 5
composer format     # Laravel Pint
composer start      # build the workbench demo app (seeded) and serve the dashboard
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Halil Cosdu](https://github.com/halilcosdu)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
