<?php

// config for HalilCosdu/Slower

use HalilCosdu\Slower\Models\SlowLog;

return [
    'enabled' => env('SLOWER_ENABLED', true),
    'threshold' => env('SLOWER_THRESHOLD', 10000),
    'resources' => [
        'table_name' => (new SlowLog)->getTable(),
        'model' => SlowLog::class,
    ],
    'ai_recommendation' => env('SLOWER_AI_RECOMMENDATION', true),
    'open_ai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'organization' => env('OPENAI_ORGANIZATION'),
        'request_timeout' => env('OPENAI_TIMEOUT'),
    ],
    'prompt' => env('SLOWER_PROMPT', 'As a distinguished database optimization expert, your expertise is invaluable for refining SQL queries to achieve maximum efficiency. Please examine the SQL statement provided below. Based on your analysis, could you recommend sophisticated indexing techniques or query modifications that could significantly improve performance and scalability?'),
];
