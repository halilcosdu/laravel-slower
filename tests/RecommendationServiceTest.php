<?php

use HalilCosdu\Slower\AiServiceDrivers\Contracts\AiServiceDriver;
use HalilCosdu\Slower\Models\SlowLog;
use HalilCosdu\Slower\Services\RecommendationService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->aiService = Mockery::mock(AiServiceDriver::class);
    $this->recommendationService = new RecommendationService($this->aiService);
});

describe('RecommendationService', function () {
    it('generates recommendation for a valid record', function () {
        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn('SELECT * FROM users WHERE id = ?');
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn('SELECT * FROM users WHERE id = 1');
        $record->shouldReceive('update')->once()->andReturn(true);

        DB::shouldReceive('connection')->with('mysql')->andReturnSelf();
        DB::shouldReceive('getSchemaBuilder')->andReturnSelf();
        DB::shouldReceive('getColumnListing')->with('users')->andReturn(['id', 'name', 'email']);
        DB::shouldReceive('getIndexes')->with('users')->andReturn([]);
        DB::shouldReceive('getColumnType')->andReturn('integer');

        config(['slower.recommendation_use_explain' => false]);

        $this->aiService->shouldReceive('analyze')->once()->andReturn('Add index on id column');

        $result = $this->recommendationService->getRecommendation($record);

        expect($result)->toBe('Add index on id column');
    });

    it('handles null AI response gracefully', function () {
        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn('SELECT * FROM users');
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn('SELECT * FROM users');
        $record->shouldReceive('update')->once()->with([
            'is_analyzed' => true,
            'recommendation' => null,
        ])->andReturn(true);

        DB::shouldReceive('connection')->with('mysql')->andReturnSelf();
        DB::shouldReceive('getSchemaBuilder')->andReturnSelf();
        DB::shouldReceive('getColumnListing')->with('users')->andReturn(['id']);
        DB::shouldReceive('getIndexes')->with('users')->andReturn([]);
        DB::shouldReceive('getColumnType')->andReturn('integer');

        config(['slower.recommendation_use_explain' => false]);

        $this->aiService->shouldReceive('analyze')->once()->andReturn(null);

        $result = $this->recommendationService->getRecommendation($record);

        expect($result)->toBeNull();
    });

    it('includes EXPLAIN ANALYSE when enabled', function () {
        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn('SELECT * FROM users');
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn('SELECT * FROM users');
        $record->shouldReceive('update')->once()->andReturn(true);

        DB::shouldReceive('connection')->with('mysql')->andReturnSelf();
        DB::shouldReceive('getSchemaBuilder')->andReturnSelf();
        DB::shouldReceive('getColumnListing')->with('users')->andReturn(['id']);
        DB::shouldReceive('getIndexes')->with('users')->andReturn([]);
        DB::shouldReceive('getColumnType')->andReturn('integer');
        DB::shouldReceive('select')->with('explain analyse SELECT * FROM users')->andReturn([
            (object) ['QUERY PLAN' => 'Seq Scan on users'],
        ]);

        config(['slower.recommendation_use_explain' => true]);

        $this->aiService->shouldReceive('analyze')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'EXPLAIN ANALYSE output');
            })
            ->andReturn('Recommendation with explain');

        $result = $this->recommendationService->getRecommendation($record);

        expect($result)->toBe('Recommendation with explain');
    });

    it('extracts multiple table names from complex queries', function () {
        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn('SELECT * FROM users JOIN orders ON users.id = orders.user_id');
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn('SELECT * FROM users JOIN orders ON users.id = orders.user_id');
        $record->shouldReceive('update')->once()->andReturn(true);

        DB::shouldReceive('connection')->with('mysql')->andReturnSelf();
        DB::shouldReceive('getSchemaBuilder')->andReturnSelf();
        DB::shouldReceive('getColumnListing')->with('users')->andReturn(['id', 'name']);
        DB::shouldReceive('getColumnListing')->with('orders')->andReturn(['id', 'user_id']);
        DB::shouldReceive('getIndexes')->andReturn([]);
        DB::shouldReceive('getColumnType')->andReturn('integer');

        config(['slower.recommendation_use_explain' => false]);

        $this->aiService->shouldReceive('analyze')->once()->andReturn('Add foreign key index');

        $result = $this->recommendationService->getRecommendation($record);

        expect($result)->toBe('Add foreign key index');
    });

    it('handles UPDATE queries', function () {
        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn('UPDATE users SET name = ? WHERE id = ?');
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn('UPDATE users SET name = "John" WHERE id = 1');
        $record->shouldReceive('update')->once()->andReturn(true);

        DB::shouldReceive('connection')->with('mysql')->andReturnSelf();
        DB::shouldReceive('getSchemaBuilder')->andReturnSelf();
        DB::shouldReceive('getColumnListing')->with('users')->andReturn(['id', 'name']);
        DB::shouldReceive('getIndexes')->with('users')->andReturn([]);
        DB::shouldReceive('getColumnType')->andReturn('varchar');

        config(['slower.recommendation_use_explain' => false]);

        $this->aiService->shouldReceive('analyze')->once()->andReturn('Optimize update query');

        $result = $this->recommendationService->getRecommendation($record);

        expect($result)->toBe('Optimize update query');
    });

    it('handles INSERT INTO queries', function () {
        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn('INSERT INTO users (name, email) VALUES (?, ?)');
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn('INSERT INTO users (name, email) VALUES ("John", "john@example.com")');
        $record->shouldReceive('update')->once()->andReturn(true);

        DB::shouldReceive('connection')->with('mysql')->andReturnSelf();
        DB::shouldReceive('getSchemaBuilder')->andReturnSelf();
        DB::shouldReceive('getColumnListing')->with('users')->andReturn(['id', 'name', 'email']);
        DB::shouldReceive('getIndexes')->with('users')->andReturn([]);
        DB::shouldReceive('getColumnType')->andReturn('varchar');

        config(['slower.recommendation_use_explain' => false]);

        $this->aiService->shouldReceive('analyze')->once()->andReturn('Consider bulk insert');

        $result = $this->recommendationService->getRecommendation($record);

        expect($result)->toBe('Consider bulk insert');
    });

    it('handles queries with table aliases', function () {
        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn('SELECT * FROM users AS u JOIN orders AS o ON u.id = o.user_id');
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn('SELECT * FROM users AS u JOIN orders AS o ON u.id = o.user_id');
        $record->shouldReceive('update')->once()->andReturn(true);

        DB::shouldReceive('connection')->with('mysql')->andReturnSelf();
        DB::shouldReceive('getSchemaBuilder')->andReturnSelf();
        DB::shouldReceive('getColumnListing')->with('users')->andReturn(['id', 'name']);
        DB::shouldReceive('getColumnListing')->with('orders')->andReturn(['id', 'user_id']);
        DB::shouldReceive('getIndexes')->andReturn([]);
        DB::shouldReceive('getColumnType')->andReturn('integer');

        config(['slower.recommendation_use_explain' => false]);

        $this->aiService->shouldReceive('analyze')->once()->andReturn('Add index on foreign key');

        $result = $this->recommendationService->getRecommendation($record);

        expect($result)->toBe('Add index on foreign key');
    });

    it('handles queries with backticks', function () {
        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn('SELECT * FROM `users`');
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn('SELECT * FROM `users`');
        $record->shouldReceive('update')->once()->andReturn(true);

        DB::shouldReceive('connection')->with('mysql')->andReturnSelf();
        DB::shouldReceive('getSchemaBuilder')->andReturnSelf();
        DB::shouldReceive('getColumnListing')->with('users')->andReturn(['id']);
        DB::shouldReceive('getIndexes')->with('users')->andReturn([]);
        DB::shouldReceive('getColumnType')->andReturn('integer');

        config(['slower.recommendation_use_explain' => false]);

        $this->aiService->shouldReceive('analyze')->once()->andReturn('Recommendation');

        $result = $this->recommendationService->getRecommendation($record);

        expect($result)->toBe('Recommendation');
    });

    it('handles queries with double quotes', function () {
        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('pgsql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('pgsql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn('SELECT * FROM "users"');
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn('SELECT * FROM "users"');
        $record->shouldReceive('update')->once()->andReturn(true);

        DB::shouldReceive('connection')->with('pgsql')->andReturnSelf();
        DB::shouldReceive('getSchemaBuilder')->andReturnSelf();
        DB::shouldReceive('getColumnListing')->with('users')->andReturn(['id']);
        DB::shouldReceive('getIndexes')->with('users')->andReturn([]);
        DB::shouldReceive('getColumnType')->andReturn('integer');

        config(['slower.recommendation_use_explain' => false]);

        $this->aiService->shouldReceive('analyze')->once()->andReturn('Recommendation');

        $result = $this->recommendationService->getRecommendation($record);

        expect($result)->toBe('Recommendation');
    });
});

describe('Table Name Extraction Edge Cases', function () {
    it('handles queries with subqueries', function () {
        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn('SELECT * FROM (SELECT * FROM users) as subquery');
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn('SELECT * FROM (SELECT * FROM users) as subquery');
        $record->shouldReceive('update')->once()->andReturn(true);

        DB::shouldReceive('connection')->with('mysql')->andReturnSelf();
        DB::shouldReceive('getSchemaBuilder')->andReturnSelf();
        DB::shouldReceive('getColumnListing')->with('users')->andReturn(['id']);
        DB::shouldReceive('getIndexes')->with('users')->andReturn([]);
        DB::shouldReceive('getColumnType')->andReturn('integer');

        config(['slower.recommendation_use_explain' => false]);

        $aiService = Mockery::mock(AiServiceDriver::class);
        $aiService->shouldReceive('analyze')->once()->andReturn('Optimize subquery');

        $recommendationService = new RecommendationService($aiService);
        $result = $recommendationService->getRecommendation($record);

        expect($result)->toBe('Optimize subquery');
    });

    it('handles queries with LEFT JOIN', function () {
        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn('SELECT * FROM users LEFT JOIN posts ON users.id = posts.user_id');
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn('SELECT * FROM users LEFT JOIN posts ON users.id = posts.user_id');
        $record->shouldReceive('update')->once()->andReturn(true);

        DB::shouldReceive('connection')->with('mysql')->andReturnSelf();
        DB::shouldReceive('getSchemaBuilder')->andReturnSelf();
        DB::shouldReceive('getColumnListing')->with('users')->andReturn(['id']);
        DB::shouldReceive('getColumnListing')->with('posts')->andReturn(['id', 'user_id']);
        DB::shouldReceive('getIndexes')->andReturn([]);
        DB::shouldReceive('getColumnType')->andReturn('integer');

        config(['slower.recommendation_use_explain' => false]);

        $aiService = Mockery::mock(AiServiceDriver::class);
        $aiService->shouldReceive('analyze')->once()->andReturn('Add index');

        $recommendationService = new RecommendationService($aiService);
        $result = $recommendationService->getRecommendation($record);

        expect($result)->toBe('Add index');
    });

    it('handles queries with RIGHT JOIN', function () {
        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn('SELECT * FROM users RIGHT JOIN posts ON users.id = posts.user_id');
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn('SELECT * FROM users RIGHT JOIN posts ON users.id = posts.user_id');
        $record->shouldReceive('update')->once()->andReturn(true);

        DB::shouldReceive('connection')->with('mysql')->andReturnSelf();
        DB::shouldReceive('getSchemaBuilder')->andReturnSelf();
        DB::shouldReceive('getColumnListing')->with('users')->andReturn(['id']);
        DB::shouldReceive('getColumnListing')->with('posts')->andReturn(['id', 'user_id']);
        DB::shouldReceive('getIndexes')->andReturn([]);
        DB::shouldReceive('getColumnType')->andReturn('integer');

        config(['slower.recommendation_use_explain' => false]);

        $aiService = Mockery::mock(AiServiceDriver::class);
        $aiService->shouldReceive('analyze')->once()->andReturn('Optimize join');

        $recommendationService = new RecommendationService($aiService);
        $result = $recommendationService->getRecommendation($record);

        expect($result)->toBe('Optimize join');
    });

    it('handles queries with INNER JOIN', function () {
        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn('SELECT * FROM users INNER JOIN posts ON users.id = posts.user_id');
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn('SELECT * FROM users INNER JOIN posts ON users.id = posts.user_id');
        $record->shouldReceive('update')->once()->andReturn(true);

        DB::shouldReceive('connection')->with('mysql')->andReturnSelf();
        DB::shouldReceive('getSchemaBuilder')->andReturnSelf();
        DB::shouldReceive('getColumnListing')->with('users')->andReturn(['id']);
        DB::shouldReceive('getColumnListing')->with('posts')->andReturn(['id', 'user_id']);
        DB::shouldReceive('getIndexes')->andReturn([]);
        DB::shouldReceive('getColumnType')->andReturn('integer');

        config(['slower.recommendation_use_explain' => false]);

        $aiService = Mockery::mock(AiServiceDriver::class);
        $aiService->shouldReceive('analyze')->once()->andReturn('Optimize inner join');

        $recommendationService = new RecommendationService($aiService);
        $result = $recommendationService->getRecommendation($record);

        expect($result)->toBe('Optimize inner join');
    });
});
