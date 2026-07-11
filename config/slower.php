<?php

// config for HalilCosdu/Slower

use HalilCosdu\Slower\Http\Middleware\Authorize;
use HalilCosdu\Slower\Models\SlowLog;

return [
    'enabled' => env('SLOWER_ENABLED', true),
    'threshold' => env('SLOWER_THRESHOLD', 10000),
    // Any Prism provider — openai, anthropic, gemini, ollama, … — or a custom
    // driver registered via AiServiceManager::extend(). Provider credentials
    // live in Prism's own config (config/prism.php): set OPENAI_API_KEY,
    // ANTHROPIC_API_KEY or GEMINI_API_KEY.
    'ai_service' => env('SLOWER_AI_SERVICE', 'openai'),
    'capture' => [
        // Fraction of threshold-exceeding queries that get recorded (0.0–1.0).
        // Lower it on very high-traffic apps; counts become approximate.
        'sample_rate' => env('SLOWER_SAMPLE_RATE', 1.0),
        // Hard cap on captures per request / job / command run. Stops a single
        // runaway execution from flooding the log table. null = unlimited.
        'max_per_execution' => env('SLOWER_MAX_PER_EXECUTION', 50),
        'origin' => [
            // Record where each slow query came from: route/controller, job
            // class, artisan command, and the first application code frame.
            'enabled' => env('SLOWER_CAPTURE_ORIGIN', true),
            // The authenticated user id is privacy-sensitive; opt in explicitly.
            'user_id' => env('SLOWER_CAPTURE_USER_ID', false),
        ],
    ],
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
    // Queue name for AI analysis. null = analyze synchronously (no worker
    // needed). Set a queue name (e.g. "default") to run dashboard analysis
    // and `slower:analyze --queue` as background jobs.
    'analyze_queue' => env('SLOWER_ANALYZE_QUEUE'),
    'ai_payload' => [
        // Safe by default: only the parameterized SQL (no literal values) plus
        // schema/EXPLAIN context is sent to the AI provider. Raw SQL and
        // bindings can contain user data, tokens and other secrets — opt in
        // deliberately, and pair with a redactor for defense in depth.
        'send_raw_sql' => env('SLOWER_AI_SEND_RAW_SQL', false),
        'send_bindings' => env('SLOWER_AI_SEND_BINDINGS', false),
        // Class implementing HalilCosdu\Slower\Contracts\PayloadRedactor,
        // applied to the opted-in raw SQL / bindings before sending.
        'redactor' => null,
    ],
    // Leave null to use a sensible low-cost default for the selected provider.
    'recommendation_model' => env('SLOWER_AI_RECOMMENDATION_MODEL'),
    'recommendation_use_explain' => env('SLOWER_AI_RECOMMENDATION_USE_EXPLAIN', true),
    'ignore_explain_queries' => env('SLOWER_IGNORE_EXPLAIN_QUERIES', true),
    'ignore_insert_queries' => env('SLOWER_IGNORE_INSERT_QUERIES', true),
    'prompt' => env('SLOWER_PROMPT', 'As a distinguished database optimization expert, your expertise is invaluable for refining SQL queries to achieve maximum efficiency. Schema json provide list of indexes and column definitions for each table in query. Also analyse the output of EXPLAIN and provide recommendations to optimize query. Please examine the SQL statement provided below including EXPLAIN query plan. Based on your analysis, could you recommend sophisticated indexing techniques or query modifications that could significantly improve performance and scalability?'),
];
