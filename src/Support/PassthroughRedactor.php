<?php

namespace HalilCosdu\Slower\Support;

use HalilCosdu\Slower\Contracts\PayloadRedactor;

/**
 * The default redactor: leaves values untouched. Used when no custom
 * redactor is configured — the opt-in flags themselves are the guard.
 */
class PassthroughRedactor implements PayloadRedactor
{
    public function redactBindings(array $bindings): array
    {
        return $bindings;
    }

    public function redactRawSql(string $rawSql): string
    {
        return $rawSql;
    }
}
