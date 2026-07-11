<div align="center">

# Laravel Slower

**Find your slowest database queries — and let AI tell you how to fix them.**

<a href="https://trendshift.io/repositories/10023?utm_source=trendshift-badge&amp;utm_medium=badge&amp;utm_campaign=badge-trendshift-10023" target="_blank" rel="noopener noreferrer"><img src="https://trendshift.io/api/badge/trendshift/repositories/10023/daily?language=PHP" alt="halilcosdu%2Flaravel-slower | Trendshift" width="250" height="55"/></a>

[![Latest Version on Packagist](https://img.shields.io/packagist/v/halilcosdu/laravel-slower.svg?style=flat-square)](https://packagist.org/packages/halilcosdu/laravel-slower)
[![Total Downloads](https://img.shields.io/packagist/dt/halilcosdu/laravel-slower.svg?style=flat-square)](https://packagist.org/packages/halilcosdu/laravel-slower)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-11--13-FF2D20?style=flat-square&logo=laravel)](https://laravel.com/)
[![License](https://img.shields.io/badge/license-MIT-green?style=flat-square)](LICENSE.md)

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="art/dashboard-dark.png">
  <img src="art/dashboard-light.png" alt="The Laravel Slower dashboard listing slow queries with duration bars, filters and AI analysis status" width="900">
</picture>

</div>

Laravel Slower watches every query your application runs, captures the ones that cross your threshold, and uses AI to recommend indexes and query rewrites. It speaks to every major LLM — **OpenAI, Anthropic (Claude), Google Gemini, or any custom/self-hosted model** — through one official package. And since **v2.3** it ships with a **built-in dashboard**: install the package and you have a full slow-query UI at `/slower`, with zero frontend work — no npm, no CDN, no assets to publish.

## Contents

- [Features](#features)
- [How it works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [The Dashboard](#the-dashboard)
- [Configuration](#configuration)
- [AI providers](#ai-providers)
- [Commands and scheduling](#commands-and-scheduling)
- [Programmatic usage](#programmatic-usage)
- [Development and testing](#development-and-testing)

## Features

- 🚨 **Automatic capture** — a `DB::listen` hook logs every query slower than your threshold, with bindings and resolved SQL.
- 🤖 **AI recommendations** — sends the query, schema, indexes and a safe `EXPLAIN` plan to your LLM of choice (OpenAI, Anthropic, Gemini, or a custom driver) and stores actionable optimization advice.
- 🧩 **Any major LLM, one variable** — switch providers with `SLOWER_AI_SERVICE`; credentials live in Prism's config, not Slower's. Bring your own model with a one-method driver.
- 📊 **Built-in dashboard** — stats, search, filters, sorting, query detail with rendered recommendations, one-click *Analyze with AI*, cleanup tools. Dark and light theme, fully self-contained.
- 🛡️ **Safe by default** — the dashboard is only accessible in the `local` environment until you explicitly open it, AI actions are rate-limited and capped, destructive actions ask first.
- ⏰ **Scheduler-friendly commands** — `slower:analyze` and `slower:clean` for bulk analysis and retention.

## How it works

```text
every query → DB::listen (timed) → slower than threshold → slow_logs → AI analysis → recommendation → /slower
```

1. **Capture.** A `DB::listen` hook times every query. Anything slower than `threshold` (ms) is written to the `slow_logs` table with its SQL, bindings and connection. Faster queries are ignored — nothing is stored.
2. **Analyze.** `slower:analyze` (or the dashboard's *Analyze with AI* button) sends each unanalyzed query — plus its table schema, indexes and a safe, read-only `EXPLAIN` plan — to your configured LLM (OpenAI, Claude, Gemini or a custom driver).
3. **Recommend.** The advice (indexes, rewrites, data-type fixes) is stored on the record and rendered as markdown in the dashboard, ready to act on.

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

To enable AI recommendations, [pick a provider](#ai-providers) and set its API key.

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

A few keys worth tuning:

- **`threshold`** — the millisecond bar for "slow". Lower it in staging to surface more, raise it in production to keep the table lean.
- **`ai_recommendation`** — set to `false` to keep logging slow queries while never calling an AI API (no charges).
- **`recommendation_use_explain`** — attaches a safe, read-only `EXPLAIN` plan to the prompt for sharper advice.

## AI providers

Slower talks to every major LLM through one official package — [Prism](https://prismphp.com). There are **no provider credentials in Slower's own config**: you pick a provider with a single variable, and Prism reads the key from its own config (`config/prism.php`), which in turn reads the conventional environment variables.

```dotenv
SLOWER_AI_SERVICE=openai   # openai · anthropic · gemini · ollama · … · or a custom driver
```

| `ai_service` | Default model |
|---|---|
| `openai` *(default)* | `gpt-5.4-mini` |
| `anthropic` | `claude-haiku-4-5` |
| `gemini` | `gemini-2.5-flash` |
| any other Prism provider | *you must set the model* |

Override any default with `SLOWER_AI_RECOMMENDATION_MODEL`.

> [!NOTE]
> Upgrading from an OpenAI-only version? Nothing to change — Prism reads your existing `OPENAI_API_KEY`, and a boot-time bridge still honors a legacy `slower.open_ai.api_key`.

Below is the exact setup for each major provider. In every case **only two lines are required** — `SLOWER_AI_SERVICE` and the provider's API key; everything else is an optional override, shown commented out with its default value.

### OpenAI

```dotenv
SLOWER_AI_SERVICE=openai
OPENAI_API_KEY=sk-...

# Optional overrides (defaults shown)
# SLOWER_AI_RECOMMENDATION_MODEL=gpt-5.4-mini
# OPENAI_URL=https://api.openai.com/v1     # point at Azure OpenAI or a proxy
# OPENAI_ORGANIZATION=
# OPENAI_PROJECT=
```

Get a key at [platform.openai.com](https://platform.openai.com/api-keys).

### Anthropic (Claude)

```dotenv
SLOWER_AI_SERVICE=anthropic
ANTHROPIC_API_KEY=sk-ant-...

# Optional overrides (defaults shown)
# SLOWER_AI_RECOMMENDATION_MODEL=claude-haiku-4-5
# ANTHROPIC_API_VERSION=2023-06-01
# ANTHROPIC_URL=https://api.anthropic.com/v1
```

Get a key at [console.anthropic.com](https://console.anthropic.com/).

### Google Gemini

```dotenv
SLOWER_AI_SERVICE=gemini
GEMINI_API_KEY=...

# Optional overrides (defaults shown)
# SLOWER_AI_RECOMMENDATION_MODEL=gemini-2.5-flash
# GEMINI_URL=https://generativelanguage.googleapis.com/v1beta/models
```

Get a key at [aistudio.google.com](https://aistudio.google.com/apikey).

### Self-hosted & OpenAI-compatible (Ollama, LM Studio, OpenRouter, Groq, …)

Any Prism provider works. These have **no built-in default model**, so you must name one:

```dotenv
SLOWER_AI_SERVICE=ollama
SLOWER_AI_RECOMMENDATION_MODEL=qwen2.5-coder

# Optional overrides (default shown)
# OLLAMA_URL=http://localhost:11434
```

### A fully custom driver

For a bespoke backend, register a driver in a service provider — no HTTP code required from Slower:

```php
use HalilCosdu\Slower\AiServiceDrivers\AiServiceManager;
use HalilCosdu\Slower\AiServiceDrivers\Contracts\AiServiceDriver;

app(AiServiceManager::class)->extend('my-llm', fn () => new class implements AiServiceDriver
{
    public function analyze(string $userMessage): ?string
    {
        // Call your model. Return the recommendation text, or null to retry later.
    }
});
```

Then set `SLOWER_AI_SERVICE=my-llm`.

> [!TIP]
> Model ids move fast. If a default drifts, pin the current low-cost model for your provider with `SLOWER_AI_RECOMMENDATION_MODEL`. AI requests time out after Prism's default of 30 seconds — raise it with `PRISM_REQUEST_TIMEOUT` (seconds) for very large schemas or slower models.

## Commands and scheduling

```bash
php artisan slower:analyze      # analyze every record where is_analyzed=false
php artisan slower:clean 15     # delete records older than 15 days
```

Run them on a schedule so analysis and retention take care of themselves:

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

Everything the dashboard does is available through the `Slower` facade and the `SlowLog` model.

```php
use HalilCosdu\Slower\Facades\Slower;
use HalilCosdu\Slower\Models\SlowLog;

// Analyze a single captured query — returns the analyzed model.
$record = SlowLog::first();

Slower::analyze($record);

$record->raw_sql;        // select count(*) as aggregate from "product_prices" where ...
$record->recommendation; // the AI's optimization advice (markdown)
```

Because slow queries are plain Eloquent records, you can query and act on them however you like:

```php
use HalilCosdu\Slower\Facades\Slower;
use HalilCosdu\Slower\Models\SlowLog;

// How many queries are still waiting for analysis?
$pending = SlowLog::where('is_analyzed', false)->count();

// Analyze the twenty slowest unanalyzed queries.
SlowLog::query()
    ->where('is_analyzed', false)
    ->orderByDesc('time')
    ->limit(20)
    ->get()
    ->each(fn (SlowLog $log) => Slower::analyze($log));
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

## Development and testing

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
