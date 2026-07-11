<?php

namespace HalilCosdu\Slower\Contracts;

/**
 * Redacts sensitive values from the opt-in parts of the AI payload before
 * they leave the application. Only consulted when `slower.ai_payload`
 * enables raw SQL and/or bindings — the default payload contains neither.
 *
 * Register an implementation via config:
 * `'ai_payload' => ['redactor' => App\Support\MyRedactor::class]`.
 */
interface PayloadRedactor
{
    /**
     * @param  array<int, mixed>  $bindings
     * @return array<int, mixed>
     */
    public function redactBindings(array $bindings): array;

    public function redactRawSql(string $rawSql): string;
}
