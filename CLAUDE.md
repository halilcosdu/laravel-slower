# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Laravel Slower is a Laravel package that automatically detects slow database queries and uses AI (OpenAI) to suggest optimization strategies like indexing and query modifications.

## Common Commands

```bash
# Run tests
composer test

# Run static analysis (PHPStan level 5)
composer analyse

# Format code (Laravel Pint)
composer format

# Build workbench environment
composer build

# Start development server
composer start
```

## Architecture

### Core Components

**SlowerServiceProvider** (`src/SlowerServiceProvider.php`)
- Registers a database listener via `DB::listen()` that captures queries exceeding the configured threshold
- Logs slow queries to the `slow_logs` table (configurable)
- Filters out EXPLAIN and INSERT queries based on config

**Slower** (`src/Slower.php`)
- Main facade class exposing `analyze(Model $record)` method
- Validates the model type and delegates to RecommendationService

**RecommendationService** (`src/Services/RecommendationService.php`)
- Extracts table schema (columns, indexes) from the SQL query
- Builds a prompt with query details, execution time, schema, and optionally EXPLAIN ANALYSE output
- Sends to AI service and updates the record with recommendations

### AI Driver System

Uses Laravel's Manager pattern for AI service abstraction:
- `AiServiceManager` (`src/AiServiceDrivers/AiServiceManager.php`) - Factory for AI drivers
- `AiServiceDriver` interface (`src/AiServiceDrivers/Contracts/AiServiceDriver.php`) - Contract with `analyze(string $userMessage): ?string`
- `OpenAiDriver` (`src/AiServiceDrivers/OpenAiDriver.php`) - Default implementation using openai-php/laravel

To add a new AI provider, implement `AiServiceDriver` and add a `create{Name}Driver()` method in `AiServiceManager`.

### Artisan Commands

- `slower:analyze` - Processes unanalyzed records (`is_analyzed=false`) and generates AI recommendations
- `slower:clean {days=15}` - Deletes records older than specified days

### Key Configuration Options (config/slower.php)

- `threshold` - Query time in milliseconds to trigger logging (default: 10000ms)
- `ai_recommendation` - Enable/disable AI analysis
- `recommendation_model` - OpenAI model to use (default: gpt-4)
- `recommendation_use_explain` - Include EXPLAIN ANALYSE in prompts

## Testing

Tests use Pest PHP. Run a single test file:
```bash
vendor/bin/pest tests/SlowerTest.php
```

Run specific test:
```bash
vendor/bin/pest --filter="analyzes a model"
```
