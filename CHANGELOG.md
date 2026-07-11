# Changelog

All notable changes to `laravel-slower` will be documented in this file.

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
