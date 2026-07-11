# Changelog

All notable changes to `laravel-slower` will be documented in this file.

## v3.2.0 - 2026-07-11

The first phase of the 2026 roadmap: **safe capture foundation**. Slower becomes something you confidently leave on in production — and every capture now knows what it is (fingerprint) and where it came from (origin).

### Added
- **Query fingerprints & the Grouped view.** Every capture gets a versioned fingerprint computed from the *parameterized* SQL (string/number literals, comments, whitespace, placeholder style and `IN (...)` sizes normalized away in a single lexer-style pass). The dashboard gains an *Events | Grouped* toggle: one row per query shape **per connection**, with occurrence count, avg/max duration and last-seen, with drill-down to the underlying events. The `SlowQueryFirstSeen` event uses the same per-connection identity. `php artisan slower:fingerprint` backfills existing records (chunked, idempotent).
- **Origin context.** Captures record their origin — HTTP route/URI/`Controller@action`, queue job class, or artisan command — plus the first `file:line` of application code in the stack (taken only for threshold-exceeding queries, with `DEBUG_BACKTRACE_IGNORE_ARGS`). Shown on the detail page and included in the AI prompt for sharper advice. The authenticated user id is **opt-in** (`SLOWER_CAPTURE_USER_ID`); origin capture itself can be disabled.
- **Production controls.** `capture.sample_rate` (capture a fraction of slow queries), `capture.max_per_execution` (hard cap per request/job/command), a 60-second circuit breaker when storing captures fails, and a hardened self-capture guard.
- **Safe-by-default AI payload.** Only the parameterized SQL (plus schema, origin and EXPLAIN context) leaves the application. Raw SQL and bindings are explicit opt-ins (`ai_payload.send_raw_sql` / `send_bindings`) and pass through an optional `PayloadRedactor` implementation; a misconfigured redactor throws instead of silently passing secrets. Canary-secret tests pin the contract.
- **Queued analysis.** Set `SLOWER_ANALYZE_QUEUE=<queue>` and the dashboard's analyze actions dispatch unique-per-record background jobs instead of blocking the request; `slower:analyze --queue` does the same for bulk analysis. Unset, everything stays synchronous — no worker required.
- **Events.** `SlowQueryCaptured` (every capture) and `SlowQueryFirstSeen` (first time a query shape appears) — wire them to Slack/mail/webhooks with a plain Laravel listener; the package deliberately ships no notification channels.

### Changed
- New records store `fingerprint`, `fingerprint_version` and `origin` (additive migration; existing rows keep working and can be backfilled).
- The AI prompt now includes the origin context when available.

### Upgrade
Publish and run the new migration, then optionally backfill fingerprints:

```bash
php artisan vendor:publish --tag="slower-migrations"
php artisan migrate
php artisan slower:fingerprint
```

No breaking changes: the events view, existing config keys, `AiServiceDriver`, and the sync analysis path are untouched. New config keys all have safe defaults — to customize them, re-publish the config or copy the `capture` / `ai_payload` / `analyze_queue` blocks from the README.

## v3.1.1 - 2026-07-11

### Documentation
- **README modernization.** Each major LLM provider now has its own copy-paste configuration block — **OpenAI, Anthropic (Claude), Google Gemini, self-hosted/OpenAI-compatible (Ollama, LM Studio, OpenRouter, Groq), and a fully custom driver** — each showing the two required lines (`SLOWER_AI_SERVICE` + the provider's API key) and every optional override commented out with its real Prism default (URL, organization, project, API version). Added a table of contents, a capture → analyze → recommend flow, a provider/model-default table, richer programmatic-usage examples (facade, counting pending, batch-analyzing the slowest queries), PHP/Laravel/License badges, and GitHub note/tip/warning callouts.
- Docs only — no code, configuration, or behavior changes.

## v3.1.0 - 2026-07-11

### Added
- **All major LLM providers.** Slower now supports **OpenAI, Anthropic (Claude), Gemini, and custom/self-hosted LLMs** through [Prism](https://prismphp.com). Switch with one variable: `SLOWER_AI_SERVICE=openai|anthropic|gemini|ollama|…`. Any Prism provider works out of the box; a fully custom backend registers via `AiServiceManager::extend()`.
- Sensible low-cost default model per provider (`gpt-5.4-mini`, `claude-haiku-4-5`, `gemini-2.5-flash`), overridable with `SLOWER_AI_RECOMMENDATION_MODEL`.

### Changed
- **Minimal config.** Provider credentials are delegated to Prism's `config/prism.php` (`OPENAI_API_KEY` / `ANTHROPIC_API_KEY` / `GEMINI_API_KEY`); Slower's own `open_ai` config block is removed. `recommendation_model` now defaults to `null` (the provider's default). The `AiServiceDriver` contract and `AiServiceManager::extend()` are unchanged.
- Replaced the `openai-php/laravel` dependency with `prism-php/prism`. The whole integration lives behind one `PrismDriver`, so the rest of the package is decoupled from the provider layer.

### Upgrade
Existing OpenAI users need **no changes** — Prism reads your `OPENAI_API_KEY`, and a boot-time bridge still honors a legacy `slower.open_ai.api_key`. To use Claude or Gemini: `composer update`, set `ANTHROPIC_API_KEY`/`GEMINI_API_KEY`, and set `SLOWER_AI_SERVICE`. If your old published config hardcodes `recommendation_model`, clear it (or set the new provider's model) before switching providers. AI requests now time out after Prism's default of **30 seconds**; if your analyses legitimately run longer (very large schemas or a slow model), raise it with `PRISM_REQUEST_TIMEOUT` (seconds). Users who previously relied on `OPENAI_TIMEOUT` should set `PRISM_REQUEST_TIMEOUT` instead.

## v3.0.0 - 2026-07-11

Platform modernization. No public API, config, or database changes — only the supported runtime and the development toolchain moved forward.

### Changed
- **Requires PHP 8.3+ and Laravel 11, 12, or 13.** Laravel 10 and PHP 8.2 support are dropped. The package already relied on the Laravel 11+ `casts()` model method, so Laravel 10 was effectively unsupported; this makes the constraint honest.
- **`openai-php/laravel` raised to `^0.20.0`** — the previous `^0.18.0` capped at Laravel 12 and silently blocked Laravel 13 installs.
- **Modernized the dev/test toolchain** to a single stack: Pest 4, `pest-plugin-laravel` 4 (first line to support Laravel 13), PHPStan 2 / larastan 3 (resolving the PHPStan 1-vs-2 dependency conflict), testbench 9–11.
- **CI** now covers PHP 8.3–8.5 × Laravel 11–13.

### Removed
- Dead code: the empty `notify()` hook in `SlowerServiceProvider` and its call site.

### Upgrade
No application-code, config, or migration changes are required if you already run PHP 8.3+ and Laravel 11+. Still on PHP 8.2 or Laravel 10? Stay on the `^2.3` line.

## v2.3.0 - 2026-07-11

### Added
- **Built-in dashboard.** A self-contained web UI at `/slower` (Telescope/Horizon-style) — no npm build, no CDN, no assets to publish. It lists captured slow queries with overview stats (total, pending, average and max duration), search over the resolved SQL, filters by analyzed status and connection, sortable columns, and pagination. A detail page shows keyword-formatted SQL, bindings, and the AI recommendation rendered from markdown.
- **Dashboard actions.** Analyze a single query with AI (with a per-record lock and a rate limiter to prevent duplicate/abusive paid calls), analyze up to `dashboard.analyze_pending_limit` pending queries at once, delete a single record, and clean up records older than N days (`0` clears everything). Every AI action warns it may incur provider charges; every destructive action requires confirmation.
- **Telescope-style authorization.** Access is granted by the `viewSlower` gate, which defaults to the `local` environment only. Define the gate in a service provider to open the dashboard in other environments. The gate is defined in the provider (not in config) so it is `config:cache`-safe.
- **Dependency-free, themeable frontend.** Inline CSS design tokens and a small amount of vanilla JS provide a dark/light theme (respects `prefers-color-scheme`, persists the manual choice), copy-to-clipboard, confirmation dialogs, and auto-submitting filters — with no runtime dependency added to the package.
- **`MarkdownRenderer`** — a tiny, escape-first markdown renderer (headings, bold/italic, inline code, fenced code blocks, lists) that HTML-escapes all input before any transform, so AI-generated recommendations render richly without becoming a stored-XSS vector.
- **Workbench demo app** — a seeded `testbench serve` environment (with a fake AI driver) for local development and browser testing of the dashboard.

### Changed
- **Config:** a new additive `dashboard` block (`enabled`, `path`, `domain`, `middleware`, `per_page`, `analyze_pending_limit`). Existing keys are unchanged; published configs keep working via defaults.
- **Resilient schema extraction.** `RecommendationService` now degrades gracefully when a captured query's connection is unavailable — it analyzes without schema context instead of throwing.

### Upgrade
Purely additive — no migration required, and the dashboard is disabled outside `local` by default. To use the dashboard: upgrade, then visit `/slower` locally (or define a `viewSlower` gate for other environments). Publish the config with `php artisan vendor:publish --tag="slower-config"` to customize the `dashboard` block.

## v2.2.0 - 2026-07-03

### Fixed
- **Safe EXPLAIN handling.** Recommendation analysis now uses a non-executing `EXPLAIN` (never `EXPLAIN ANALYZE`), resolves the captured query's own connection, picks the correct statement form per database driver (`pgsql`/`mysql` → `EXPLAIN`, `sqlite` → `EXPLAIN QUERY PLAN`), skips multi-statement input, and reports EXPLAIN failures without breaking the analysis flow. Previously, `explain analyse` was run unconditionally, which both fails on MySQL/SQLite and can execute the underlying query against production data.
- **Retryable recommendations.** A record is only marked `is_analyzed` when the AI actually returns a recommendation. Empty results stay `is_analyzed=false` so they are retried on the next `slower:analyze` run instead of being silently finalized. The command now reports an `Analyzed | Skipped` summary.
- **No more swallowed errors.** `SlowerServiceProvider::createRecord` now reports failures (via `report()` without the raw SQL) instead of silently discarding them, and catches `Throwable` so logging slow queries can never break the application's own request.
- **Correct cleanup iteration.** `slower:clean` and `slower:analyze` now use `chunkById` to avoid skipping records when the result set changes during iteration.

### Changed
- **Default recommendation model** changed from the deprecated `gpt-4` (shut down 2026-10-23) to `gpt-5.4-mini`. Pin `SLOWER_AI_RECOMMENDATION_MODEL=gpt-4` to keep the previous behaviour.
- **PHP 8.4** is now part of the CI test matrix.
- `openai-php/laravel` constraint raised to `^0.18.0`, and `dependabot/fetch-metadata` bumped to `2.5.0`.

### Documentation
- README config example synchronized with `slower.php` (`ai_service` documented) and the OpenAI driver/contract extension point explained.
- Removed the broken third-party screenshot hotlink.
- `OpenAiDriver` now type-hints `OpenAI\Contracts\ClientContract` instead of the final concrete `Client`, so it can be substituted/faked in tests.

## Laravel 13 Support - 2026-04-09

### What's Changed

* Added Laravel 13 support to composer.json dependencies
* Updated GitHub Actions CI workflow to test against Laravel 13.x with Orchestra Testbench 11.x
* Updated Pest and plugin version constraints for PHPUnit 11+ compatibility
* Package now supports Laravel 10.x, 11.x, 12.x, and 13.x

## Laravel 12 Support - 2025-11-05

### What's Changed

* Added Laravel 12 support to composer.json dependencies
* Updated GitHub Actions CI workflow to test against Laravel 12.x with Orchestra Testbench 10.x
* Package now supports Laravel 10.x, 11.x, and 12.x
* Bump dependabot/fetch-metadata from 2.2.0 to 2.3.0 by @dependabot in https://github.com/halilcosdu/laravel-slower/pull/29
* Bump aglipanci/laravel-pint-action from 2.4 to 2.5 by @dependabot in https://github.com/halilcosdu/laravel-slower/pull/30

**Full Changelog**: https://github.com/halilcosdu/laravel-slower/compare/v2.0.2...v2.0.3

## v2.0.2 - 2024-11-17

**Full Changelog**: https://github.com/halilcosdu/laravel-slower/compare/v2.0.0...v2.0.2

## v2.0.1 - 2024-10-30

**Full Changelog**: https://github.com/halilcosdu/laravel-slower/compare/v2.0.0...v2.0.1

## v2.0.0 - 2024-09-12

### What's Changed

* Update openai-php/laravel requirement from ^0.8.1 to ^0.9.1 by @dependabot in https://github.com/halilcosdu/laravel-slower/pull/18
* Update openai-php/laravel requirement from ^0.9.1 to ^0.10.1 by @dependabot in https://github.com/halilcosdu/laravel-slower/pull/19
* Bump dependabot/fetch-metadata from 2.1.0 to 2.2.0 by @dependabot in https://github.com/halilcosdu/laravel-slower/pull/21
* Added explain analyze query plan to recommendation prompt by @dimafe6 in https://github.com/halilcosdu/laravel-slower/pull/17

### New Contributors

* @dimafe6 made their first contribution in https://github.com/halilcosdu/laravel-slower/pull/17

**Full Changelog**: https://github.com/halilcosdu/laravel-slower/compare/v1.1.1...v2.0.0

## v1.1.1 - 2024-05-10

**Full Changelog**: https://github.com/halilcosdu/laravel-slower/compare/v1.1.0...v1.1.1

## v1.1.0 - 2024-05-10

**Full Changelog**: https://github.com/halilcosdu/laravel-slower/compare/v1.0.9...v1.1.0

## v1.0.9 - 2024-05-10

**Full Changelog**: https://github.com/halilcosdu/laravel-slower/compare/v1.0.8...v1.0.9

## v1.0.8 - 2024-05-09

**Full Changelog**: https://github.com/halilcosdu/laravel-slower/compare/v1.0.7...v1.0.8

- Schema and current indexes added.

## v1.0.7 - 2024-05-08

**Full Changelog**: https://github.com/halilcosdu/laravel-slower/compare/v1.0.6...v1.0.7

## v1.0.6 - 2024-05-08

**Full Changelog**: https://github.com/halilcosdu/laravel-slower/compare/v1.0.5...v1.0.6

## v1.0.5 - 2024-05-04

**Full Changelog**: https://github.com/halilcosdu/laravel-slower/compare/v1.0.4...v1.0.5

## v1.0.4 - 2024-05-04

**Full Changelog**: https://github.com/halilcosdu/laravel-slower/compare/v1.0.3...v1.0.4

## v1.0.3 - 2024-05-03

**Full Changelog**: https://github.com/halilcosdu/laravel-slower/compare/v1.0.2...v1.0.3

## v1.0.2 - 2024-05-03

**Full Changelog**: https://github.com/halilcosdu/laravel-slower/compare/v1.0.1...v1.0.2

## v1.0.1 - 2024-05-03

**Full Changelog**: https://github.com/halilcosdu/laravel-slower/compare/v1.0.0...v1.0.1

## v1.0.0 - 2024-05-03

### What's Changed

* Bump dependabot/fetch-metadata from 1.6.0 to 2.1.0 by @dependabot in https://github.com/halilcosdu/laravel-slower/pull/1

### New Contributors

* @dependabot made their first contribution in https://github.com/halilcosdu/laravel-slower/pull/1

**Full Changelog**: https://github.com/halilcosdu/laravel-slower/commits/v1.0.0
