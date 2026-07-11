# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Laravel Slower is a Laravel package that automatically detects slow database queries and uses AI (OpenAI, Anthropic, Gemini, or a custom LLM via Prism) to suggest optimization strategies like indexing and query modifications. Requires PHP 8.3+ and Laravel 11/12/13.

## Common Commands

```bash
composer test                                    # Run tests (Pest)
composer analyse                                 # Static analysis (PHPStan level 5)
composer format                                  # Format code (Laravel Pint)
composer build                                   # Build workbench environment
composer start                                   # Start development server

vendor/bin/pest tests/SlowerTest.php              # Run single test file
vendor/bin/pest --filter="analyzes a model"       # Run specific test
```

## Architecture

### Flow: Query Detection -> Logging -> AI Analysis

1. `SlowerServiceProvider` registers a `DB::listen()` callback that captures queries exceeding `threshold` ms
2. Guards run cheapest-first: circuit breaker (`ExecutionContext::isSuspended`, 60s after a storage failure), self-capture (table-name), sampling (`capture.sample_rate`), per-execution cap (`capture.max_per_execution`)
3. Slow queries are logged to the `slow_logs` table (configurable table name/model via `resources` config) with a `fingerprint` (versioned, from `Support/SqlFingerprinter` over the parameterized SQL), `fingerprint_version`, and `origin` json (from `Services/ExecutionContext`: http route/action, job class, artisan command, first app `file:line`; user_id opt-in)
4. `SlowQueryCaptured` fires per capture; `SlowQueryFirstSeen` when a fingerprint appears for the first time (events in `src/Events/`)
5. EXPLAIN and INSERT queries are filtered based on config flags
6. `slower:analyze` command (or `Slower::analyze($model)`, or the unique-per-record `Jobs/AnalyzeSlowLog` when `analyze_queue` is set) processes unanalyzed records
7. `RecommendationService` extracts table schema, builds the payload (parameterized SQL + origin by default; raw_sql/bindings only when `ai_payload` opts in, through an optional `Contracts/PayloadRedactor`), sends to the AI driver, and stores the recommendation

`ExecutionContext` is a per-process singleton; execution boundaries (RouteMatched, JobProcessing, CommandStarting) reset its capture counter and set origin state — this keeps queue workers/Octane correct.

### Dashboard

Routes under `/slower` (gate `viewSlower`, local-only by default). `DashboardController::index` serves two modes: `?view=events` (default, one row per capture, `?fingerprint=` drill-down filter) and `?view=grouped` (GROUP BY fingerprint+connection with occurrences/avg/max/last-seen, representative SQL via `max(id)` lookup — no window functions, sqlite/mysql/pgsql portable).

### AI Driver System

Uses Laravel's Manager pattern (`Illuminate\Support\Manager`) for AI service abstraction, backed by [Prism](https://prismphp.com):
- `AiServiceManager` - Factory; default driver set by `config('slower.ai_service')` (default: `openai`). Maps any Prism provider name (openai, anthropic, gemini, ollama, …) to a `PrismDriver`; `extend()` registers a fully custom driver.
- `AiServiceDriver` interface - Single method: `analyze(string $userMessage): ?string`
- `PrismDriver` - The only Prism-aware class; wraps `Prism::text()`. Provider credentials live in Prism's `config/prism.php` (env `OPENAI_API_KEY` / `ANTHROPIC_API_KEY` / `GEMINI_API_KEY`).

To add a custom provider: `AiServiceManager::extend('name', fn () => new YourDriver)` and set `SLOWER_AI_SERVICE=name`.

### Key Configuration (config/slower.php)

- `enabled` / `ai_recommendation` - Toggle package and AI analysis independently
- `threshold` - Query time in ms to trigger logging (default: 10000)
- `capture` - `sample_rate` (0–1), `max_per_execution` (default 50), `origin.enabled` / `origin.user_id` (user id default OFF — privacy)
- `ai_service` - Provider name (default: `openai`; also anthropic, gemini, ollama, or a custom driver)
- `analyze_queue` - null = synchronous analysis; a queue name = dispatch `AnalyzeSlowLog` jobs
- `ai_payload` - `send_raw_sql` / `send_bindings` (both default false — safe payload) + `redactor` class-string
- `recommendation_model` - AI model (default: `null` → a low-cost per-provider default)
- `recommendation_use_explain` - Include safe EXPLAIN output in prompts (may echo literals on some drivers)
- `ignore_explain_queries` / `ignore_insert_queries` - Skip these query types from logging

### Artisan Commands

- `slower:analyze {--queue}` - Processes unanalyzed records (`is_analyzed=false`) in chunks of 1000; `--queue` dispatches unique jobs instead
- `slower:clean {days=15}` - Deletes records older than specified days
- `slower:fingerprint` - Backfills fingerprints for pre-3.2 records / older algorithm versions (chunked, idempotent)

## Testing

Tests use Pest PHP with Orchestra Testbench for package testing. Base `TestCase` registers `SlowerServiceProvider` and uses an in-memory SQLite database (`database.default = testing`).

Test files: `SlowerTest` (core analysis), `ConfigTest` (config defaults), `CommandsTest` (command signatures), `SlowerServiceProviderTest` (listener registration, `normalizeBindings`), `SqlFingerprinterTest` (golden fixtures: same-shape queries must share a fingerprint, distinct shapes must not), `CapturePipelineTest` (sampling/caps/breaker/events, uses `threshold=0`), `ExecutionContextTest` (origin resolution per execution type), `AiPayloadTest` (canary secrets must never reach the driver by default), `QueuedAnalysisTest`, `DashboardGroupedViewTest`, `MigrationTest` (legacy-table upgrade path).

## CI

Tests run on PHP 8.3-8.5 with Laravel 11-13 (`prefer-lowest` and `prefer-stable`), using the Pest 4 / PHPStan 2 (larastan 3) stack. PHPStan and Pint run as separate workflows.
