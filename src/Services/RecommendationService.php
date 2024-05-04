<?php

namespace HalilCosdu\Slower\Services;

use OpenAI\Client;

class RecommendationService
{
    public function __construct(protected Client $client)
    {
        //
    }

    public function getRecommendation($record): ?string
    {

        $result = $this->client->chat()->create([
            'model' => 'gpt-4',
            'messages' => [
                ['role' => 'system', 'content' => config('slower.prompt')],
                ['role' => 'user', 'content' => 'The query execution took '.$record->time.' milliseconds.'.PHP_EOL.
                                    'Connection: '.$record->connection.PHP_EOL.
                                    'Connection Name: '.$record->connection.PHP_EOL.
                                    'Sql: '.$record->raw_sql,
                ],
            ],
        ]);

        $record->update(
            [
                'is_analyzed' => true,
                'recommendation' => $result->choices[0]->message->content,
            ]
        );

        return $result->choices[0]->message->content;
    }
}
