<?php

use HalilCosdu\Slower\AiServiceDrivers\Contracts\AiServiceDriver;
use HalilCosdu\Slower\Contracts\PayloadRedactor;
use HalilCosdu\Slower\Models\SlowLog;
use HalilCosdu\Slower\Services\RecommendationService;

/**
 * The payload contract: what leaves the application towards an LLM provider.
 * Safe by default — parameterized SQL only; raw SQL and bindings are opt-in
 * and pass through the configured redactor.
 */
class MaskEverythingRedactor implements PayloadRedactor
{
    public function redactBindings(array $bindings): array
    {
        return array_map(fn () => '[REDACTED]', $bindings);
    }

    public function redactRawSql(string $rawSql): string
    {
        return '[REDACTED SQL]';
    }
}

function analyzeAndCapturePayload(SlowLog $record): string
{
    $captured = null;

    $driver = Mockery::mock(AiServiceDriver::class);
    $driver->shouldReceive('analyze')
        ->once()
        ->with(Mockery::on(function (string $message) use (&$captured) {
            $captured = $message;

            return true;
        }))
        ->andReturn('Add an index.');
    app()->instance(AiServiceDriver::class, $driver);

    app(RecommendationService::class)->getRecommendation($record);

    return $captured;
}

function canaryRecord(array $overrides = []): SlowLog
{
    return SlowLog::factory()->create(array_merge([
        'sql' => 'select * from users where api_token = ? and email = ?',
        'raw_sql' => "select * from users where api_token = 'sk-CANARY-TOKEN' and email = 'canary@example.com'",
        'bindings' => ['sk-CANARY-TOKEN', 'canary@example.com'],
    ], $overrides));
}

describe('safe defaults', function () {
    it('sends the parameterized sql and never the raw values or bindings', function () {
        $payload = analyzeAndCapturePayload(canaryRecord());

        expect($payload)
            ->toContain('select * from users where api_token = ? and email = ?')
            ->not->toContain('sk-CANARY-TOKEN')
            ->not->toContain('canary@example.com');
    });

    it('includes the origin context when the record has one', function () {
        $payload = analyzeAndCapturePayload(canaryRecord([
            'origin' => ['type' => 'http', 'route' => 'orders.index', 'action' => 'App\Http\Controllers\OrderController@index'],
        ]));

        expect($payload)
            ->toContain('Origin:')
            ->toContain('orders.index')
            ->toContain('OrderController@index');
    });

    it('never forwards the captured user id to the AI provider', function () {
        // user_id is an opt-in for the dashboard; the LLM gains nothing from
        // it, so it must be stripped from the payload's origin line.
        $payload = analyzeAndCapturePayload(canaryRecord([
            'origin' => ['type' => 'http', 'route' => 'orders.index', 'user_id' => 424242],
        ]));

        expect($payload)
            ->toContain('orders.index')
            ->not->toContain('user_id')
            ->not->toContain('424242');
    });
});

describe('opt-in payload extras', function () {
    it('includes the raw sql only when explicitly enabled', function () {
        config(['slower.ai_payload.send_raw_sql' => true]);

        expect(analyzeAndCapturePayload(canaryRecord()))->toContain('sk-CANARY-TOKEN');
    });

    it('includes bindings only when explicitly enabled', function () {
        config(['slower.ai_payload.send_bindings' => true]);

        expect(analyzeAndCapturePayload(canaryRecord()))->toContain('canary@example.com');
    });

    it('passes opted-in raw sql and bindings through the configured redactor', function () {
        config([
            'slower.ai_payload.send_raw_sql' => true,
            'slower.ai_payload.send_bindings' => true,
            'slower.ai_payload.redactor' => MaskEverythingRedactor::class,
        ]);

        $payload = analyzeAndCapturePayload(canaryRecord());

        expect($payload)
            ->toContain('[REDACTED SQL]')
            ->toContain('[REDACTED]')
            ->not->toContain('sk-CANARY-TOKEN')
            ->not->toContain('canary@example.com');
    });

    it('rejects a redactor that does not implement the contract', function () {
        config([
            'slower.ai_payload.send_bindings' => true,
            'slower.ai_payload.redactor' => stdClass::class,
        ]);

        $driver = Mockery::mock(AiServiceDriver::class);
        $driver->shouldReceive('analyze')->never();
        app()->instance(AiServiceDriver::class, $driver);

        // Misconfigured redaction must fail loudly, never silently pass secrets.
        expect(fn () => app(RecommendationService::class)->getRecommendation(canaryRecord()))
            ->toThrow(InvalidArgumentException::class);
    });
});
