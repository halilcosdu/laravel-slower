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
        $schema = $this->extractIndexesAndSchemaFromRecord($record);
        $userMessage = 'The query execution took ' . $record->time . ' milliseconds.' . PHP_EOL .
            'Connection: ' . $record->connection . PHP_EOL .
            'Connection Name: ' . $record->connection_name . PHP_EOL .
            'Schema: ' . json_encode($schema, JSON_PRETTY_PRINT) . PHP_EOL .
            'Sql: ' . $record->sql . PHP_EOL;

        if (config('slower.recommendation_use_explain', false)) {
            $plan = collect(DB::select('explain analyse ' . $record->raw_sql))->implode('QUERY PLAN', PHP_EOL);

            $userMessage .= 'EXPLAIN ANALYSE output: ' . $plan . PHP_EOL;
        }

        $result = $this->client->chat()->create([
            'model' => config('slower.recommendation_model', 'gpt-4'),
            'messages' => [
                ['role' => 'system', 'content' => config('slower.prompt')],
                ['role' => 'user', 'content' => $userMessage],
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

        $schema = [];

        $tables = $this->getTableNamesFromRawQuery($record->raw_sql);
        foreach ($tables as $tableName) {
            $columns = $schemaBuilder->getColumnListing($tableName);
            $schema[$tableName]['indexes'] = $schemaBuilder->getIndexes($tableName);

            foreach ($columns as $column) {
                $schema[$tableName]['columns'][] = [$column => $schemaBuilder->getColumnType($tableName, $column)];
            }
        }

        return $schema;
    }

    private function getTableNamesFromRawQuery(string $sqlQuery): array
    {
        // Regular expression to match table names
        $pattern = '/(?:FROM|JOIN|INTO|UPDATE)\s+(\S+)(?:\s+(?:AS\s+)?\w+)?(?:\s+ON\s+[^ ]+)?/i';

        preg_match_all($pattern, $sqlQuery, $matches);

        // Extract table names from the matches
        $tableNames = [];
        foreach ($matches[1] as $tableName) {
            $tableNames[] = str_replace(['`', '"'], '', $tableName);
        }

        return array_unique($tableNames);
    }
}
