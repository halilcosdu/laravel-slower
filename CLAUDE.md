# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Laravel Slower is a Laravel package that automatically detects slow database queries and uses AI (OpenAI) to suggest optimization strategies like indexing and query modifications. Requires PHP 8.2+ and Laravel 10/11/12/13.

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
2. Slow queries are logged to the `slow_logs` table (configurable table name/model via `resources` config)
3. EXPLAIN and INSERT queries are filtered based on config flags
4. `slower:analyze` command (or `Slower::analyze($model)`) processes unanalyzed records
5. `RecommendationService` extracts table schema, builds a prompt, sends to the AI driver, and stores the recommendation

### AI Driver System

Uses Laravel's Manager pattern (`Illuminate\Support\Manager`) for AI service abstraction:
- `AiServiceManager` - Factory; default driver set by `config('slower.ai_service')` (default: `openai`)
- `AiServiceDriver` interface - Single method: `analyze(string $userMessage): ?string`
- `OpenAiDriver` - Default implementation using `openai-php/laravel`

To add a new AI provider: implement `AiServiceDriver`, add a `create{Name}Driver()` method in `AiServiceManager`, and set `SLOWER_AI_SERVICE={name}`.

### Key Configuration (config/slower.php)

- `enabled` / `ai_recommendation` - Toggle package and AI analysis independently
- `threshold` - Query time in ms to trigger logging (default: 10000)
- `ai_service` - Driver name (default: `openai`)
- `recommendation_model` - AI model (default: `gpt-4`)
- `recommendation_use_explain` - Include EXPLAIN ANALYSE output in prompts
- `ignore_explain_queries` / `ignore_insert_queries` - Skip these query types from logging

### Artisan Commands

- `slower:analyze` - Processes unanalyzed records (`is_analyzed=false`) in chunks of 1000
- `slower:clean {days=15}` - Deletes records older than specified days

## Testing

Tests use Pest PHP with Orchestra Testbench for package testing. Base `TestCase` registers `SlowerServiceProvider` and uses an in-memory SQLite database (`database.default = testing`).

Test files: `SlowerTest` (core analysis), `ConfigTest` (config defaults), `CommandsTest` (command signatures), `SlowerServiceProviderTest` (listener registration, `normalizeBindings`).

## CI

Tests run on PHP 8.2-8.3 with Laravel 11-13 (`prefer-lowest` and `prefer-stable`). Laravel 13 requires PHP 8.3+. PHPStan and Pint run as separate workflows.
