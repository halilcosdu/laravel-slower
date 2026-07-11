<?php

// config for HalilCosdu/Slower

use HalilCosdu\Slower\Http\Middleware\Authorize;
use HalilCosdu\Slower\Models\SlowLog;

return [
    'enabled' => env('SLOWER_ENABLED', true),
    'threshold' => env('SLOWER_THRESHOLD', 10000),
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
    'recommendation_model' => env('SLOWER_AI_RECOMMENDATION_MODEL', 'gpt-5.4-mini'),
    'recommendation_use_explain' => env('SLOWER_AI_RECOMMENDATION_USE_EXPLAIN', true),
    'ignore_explain_queries' => env('SLOWER_IGNORE_EXPLAIN_QUERIES', true),
    'ignore_insert_queries' => env('SLOWER_IGNORE_INSERT_QUERIES', true),
    'open_ai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'organization' => env('OPENAI_ORGANIZATION'),
        'request_timeout' => env('OPENAI_TIMEOUT'),
    ],
    'prompt' => env('SLOWER_PROMPT', 'As a distinguished database optimization expert, your expertise is invaluable for refining SQL queries to achieve maximum efficiency. Schema json provide list of indexes and column definitions for each table in query. Also analyse the output of EXPLAIN and provide recommendations to optimize query. Please examine the SQL statement provided below including EXPLAIN query plan. Based on your analysis, could you recommend sophisticated indexing techniques or query modifications that could significantly improve performance and scalability?'),
];
