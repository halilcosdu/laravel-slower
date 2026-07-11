<?php

namespace HalilCosdu\Slower\Services;

use HalilCosdu\Slower\AiServiceDrivers\Contracts\AiServiceDriver;
use HalilCosdu\Slower\Contracts\PayloadRedactor;
use HalilCosdu\Slower\Support\PassthroughRedactor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RecommendationService
{
    public function __construct(protected AiServiceDriver $aiService) {}

    public function getRecommendation($record): ?string
    {
        $userMessage = $this->buildPayload($record);

        if (config('slower.recommendation_use_explain', false)) {
            if ($plan = $this->getExplainPlan($record)) {
                $userMessage .= 'EXPLAIN output: '.$plan.PHP_EOL;
            }
        }

        $recommendation = $this->aiService->analyze($userMessage);

        // Only mark the record as analyzed when we actually received a
        // recommendation. Empty results stay retryable so that scheduled
        // `slower:analyze` runs get another chance at them instead of being
        // silently finalized.
        if (! empty($recommendation)) {
            $record->update([
                'is_analyzed' => true,
                'recommendation' => $recommendation,
            ]);
        }

        return $recommendation;
    }

    /**
     * What leaves the application towards the AI provider. Safe by default:
     * the parameterized SQL (no literal values) plus schema and origin
     * context. Raw SQL and bindings are opt-in (`slower.ai_payload`) and
     * pass through the configured redactor. Note that when EXPLAIN output is
     * enabled it may echo literal values from the plan — disable
     * `recommendation_use_explain` in strict environments.
     */
    private function buildPayload($record): string
    {
        $schema = $this->extractIndexesAndSchemaFromRecord($record);

        $payload = 'The query execution took '.$record->time.' milliseconds.'.PHP_EOL.
            'Connection: '.$record->connection.PHP_EOL.
            'Connection Name: '.$record->connection_name.PHP_EOL;

        // user_id is captured for the dashboard (and only as an opt-in); the
        // LLM gains nothing from it, so it never enters the payload.
        $origin = collect($record->origin ?? [])->except('user_id');

        if ($origin->isNotEmpty()) {
            $payload .= 'Origin: '.json_encode($origin->all()).PHP_EOL;
        }

        $payload .= 'Schema: '.json_encode($schema, JSON_PRETTY_PRINT).PHP_EOL.
            'Sql: '.$record->sql.PHP_EOL;

        if (config('slower.ai_payload.send_raw_sql', false)) {
            $payload .= 'Raw Sql: '.$this->redactor()->redactRawSql((string) $record->raw_sql).PHP_EOL;
        }

        if (config('slower.ai_payload.send_bindings', false)) {
            $bindings = is_array($record->bindings) ? $record->bindings : [];
            $payload .= 'Bindings: '.json_encode($this->redactor()->redactBindings($bindings)).PHP_EOL;
        }

        return $payload;
    }

    private function redactor(): PayloadRedactor
    {
        $class = config('slower.ai_payload.redactor');

        if ($class === null) {
            return new PassthroughRedactor;
        }

        $redactor = app($class);

        if (! $redactor instanceof PayloadRedactor) {
            // Fail loudly: a misconfigured redactor must never silently pass secrets.
            throw new InvalidArgumentException(sprintf(
                'slower.ai_payload.redactor [%s] must implement %s.', $class, PayloadRedactor::class
            ));
        }

        return $redactor;
    }

    /**
     * Build a safe, non-executing EXPLAIN plan for the captured query.
     *
     * Important: this deliberately only ever uses EXPLAIN (never EXPLAIN
     * ANALYZE). EXPLAIN ANALYZE actually runs the query, which is dangerous
     * against captured production SQL such as UPDATE/DELETE statements. The
     * statement form is chosen per database driver; unsupported drivers and
     * suspicious (multi-statement) input are skipped, and any EXPLAIN failure
     * is reported without breaking the analysis flow.
     */
    private function getExplainPlan($record): ?string
    {
        $rawSql = $record->raw_sql;

        // Defensive guard: never explain anything that looks like more than one statement.
        if (str_contains($rawSql, ';')) {
            return null;
        }

        try {
            $connection = DB::connection($record->connection_name);
            $driver = $connection->getDriverName();
        } catch (\Throwable $e) {
            report(new \RuntimeException('Slower could not resolve the query connection for EXPLAIN: '.$e->getMessage(), 0, $e));

            return null;
        }

        $explainSql = match ($driver) {
            'pgsql', 'mysql' => 'EXPLAIN '.$rawSql,
            'sqlite' => 'EXPLAIN QUERY PLAN '.$rawSql,
            default => null,
        };

        if ($explainSql === null) {
            return null;
        }

        try {
            return collect($connection->select($explainSql))
                ->map(static function ($row) {
                    if (property_exists($row, 'QUERY PLAN')) {
                        return $row->{'QUERY PLAN'};
                    }

                    return json_encode((array) $row);
                })
                ->implode(PHP_EOL) ?: null;
        } catch (\Throwable $e) {
            report(new \RuntimeException('Slower EXPLAIN failed: '.$e->getMessage(), 0, $e));

            return null;
        }
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
