<?php

use HalilCosdu\Slower\AiServiceDrivers\Contracts\AiServiceDriver;
use HalilCosdu\Slower\Models\SlowLog;
use HalilCosdu\Slower\Services\RecommendationService;

describe('getTableNamesFromRawQuery', function () {
    $extract = function (string $sql): array {
        $service = app(RecommendationService::class);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getTableNamesFromRawQuery');
        $method->setAccessible(true);

        return $method->invoke($service, $sql);
    };

    it('extracts a table from a FROM clause', function () use ($extract) {
        expect($extract('select * from users where id = 1'))->toContain('users');
    });

    it('extracts tables from JOIN clauses', function () use ($extract) {
        $tables = $extract('select * from posts inner join users on users.id = posts.user_id');
        expect($tables)->toContain('posts')->toContain('users');
    });

    it('extracts a table from an UPDATE statement', function () use ($extract) {
        expect($extract('update users set name = "halil" where id = 1'))->toContain('users');
    });

    it('strips double quotes from table names', function () use ($extract) {
        expect($extract('select * from "users"'))->toContain('users');
    });

    it('strips backticks from table names', function () use ($extract) {
        expect($extract('select * from `users`'))->toContain('users');
    });
});

describe('RecommendationService::getRecommendation', function () {
    it('does not mark a record analyzed when the AI returns an empty recommendation', function () {
        $ai = Mockery::mock(AiServiceDriver::class);
        $ai->shouldReceive('analyze')->andReturn(null);

        $service = new RecommendationService($ai);
        $record = SlowLog::factory()->create([
            'raw_sql' => 'select * from '.config('slower.resources.table_name'),
        ]);

        expect($service->getRecommendation($record))->toBeNull();
        expect($record->fresh()->is_analyzed)->toBeFalse();
        expect($record->fresh()->recommendation)->toBeNull();
    });

    it('marks a record analyzed and stores a non-empty recommendation', function () {
        $ai = Mockery::mock(AiServiceDriver::class);
        $ai->shouldReceive('analyze')->andReturn('Add a composite index on (product_id, price).');

        $service = new RecommendationService($ai);
        $record = SlowLog::factory()->create([
            'raw_sql' => 'select * from '.config('slower.resources.table_name'),
        ]);

        $service->getRecommendation($record);

        expect($record->fresh()->is_analyzed)->toBeTrue();
        expect($record->fresh()->recommendation)->toBe('Add a composite index on (product_id, price).');
    });

    it('runs a safe (non-executing) EXPLAIN against the sqlite driver without throwing', function () {
        $ai = Mockery::mock(AiServiceDriver::class);
        $ai->shouldReceive('analyze')->andReturn('recommendation');

        $service = new RecommendationService($ai);
        $record = SlowLog::factory()->create([
            'raw_sql' => 'select * from '.config('slower.resources.table_name'),
        ]);

        $service->getRecommendation($record);

        expect($record->fresh()->is_analyzed)->toBeTrue();
    });
});

describe('getExplainPlan', function () {
    $explain = function (string $rawSql) {
        $ai = Mockery::mock(AiServiceDriver::class);
        $service = new RecommendationService($ai);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getExplainPlan');
        $method->setAccessible(true);

        $record = SlowLog::factory()->make(['raw_sql' => $rawSql]);

        return $method->invoke($service, $record);
    };

    it('skips EXPLAIN for multi-statement raw sql', function () use ($explain) {
        expect($explain('select 1; drop table users'))->toBeNull();
    });
});
