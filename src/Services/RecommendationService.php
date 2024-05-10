<?php

namespace HalilCosdu\Slower\Services;

use Illuminate\Support\Facades\DB;
use OpenAI\Client;

class RecommendationService
{
    public function __construct(protected Client $client)
    {
        //
    }

    public function getRecommendation($record): ?string
    {

        [$indexes, $schema] = $this->extractIndexesAndSchemaFromRecord($record);

        $result = $this->client->chat()->create([
            'model' => config('slower.recommendation_model', 'gpt-4'),
            'messages' => [
                ['role' => 'system', 'content' => config('slower.prompt')],
                ['role' => 'user', 'content' => 'The query execution took '.$record->time.' milliseconds.'.PHP_EOL.
                    'Connection: '.$record->connection.PHP_EOL.
                    'Current Indexes: '.json_encode($indexes, JSON_PRETTY_PRINT).PHP_EOL.
                    'Schema: '.json_encode($schema, JSON_PRETTY_PRINT).PHP_EOL.
                    'Connection Name: '.$record->connection_name.PHP_EOL.
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

    private function extractIndexesAndSchemaFromRecord($record): array
    {
        $schemaBuilder = DB::connection($record->connection_name)->getSchemaBuilder();

        $columns = $schemaBuilder->getColumnListing($record->getTable());

        $indexes = $schemaBuilder->getIndexes($record->getTable());

        $schema = [];

        foreach ($columns as $column) {
            $schema[$column] = $schemaBuilder->getColumnType($record->getTable(), $column);
        }

        return [$indexes, $schema];
    }
}
