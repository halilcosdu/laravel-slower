<?php

use HalilCosdu\Slower\AiServiceDrivers\Contracts\AiServiceDriver;
use HalilCosdu\Slower\Models\SlowLog;
use HalilCosdu\Slower\Services\RecommendationService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->aiService = Mockery::mock(AiServiceDriver::class);
    $this->recommendationService = new RecommendationService($this->aiService);
});

describe('SQL Parsing Edge Cases', function () {
    it('handles empty SQL query', function () {
        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn('');
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn('');
        $record->shouldReceive('update')->once()->andReturn(true);

        DB::shouldReceive('connection')->with('mysql')->andReturnSelf();
        DB::shouldReceive('getSchemaBuilder')->andReturnSelf();

        config(['slower.recommendation_use_explain' => false]);

        $this->aiService->shouldReceive('analyze')->once()->andReturn('Empty query detected');

        $result = $this->recommendationService->getRecommendation($record);

        expect($result)->toBe('Empty query detected');
    });

    it('handles SQL with unicode characters', function () {
        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn('SELECT * FROM users WHERE name = ?');
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn('SELECT * FROM users WHERE name = "José 😀"');
        $record->shouldReceive('update')->once()->andReturn(true);

        DB::shouldReceive('connection')->with('mysql')->andReturnSelf();
        DB::shouldReceive('getSchemaBuilder')->andReturnSelf();
        DB::shouldReceive('getColumnListing')->with('users')->andReturn(['id', 'name']);
        DB::shouldReceive('getIndexes')->with('users')->andReturn([]);
        DB::shouldReceive('getColumnType')->andReturn('varchar');

        config(['slower.recommendation_use_explain' => false]);

        $this->aiService->shouldReceive('analyze')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'José 😀');
            })
            ->andReturn('Handle unicode properly');

        $result = $this->recommendationService->getRecommendation($record);

        expect($result)->toBe('Handle unicode properly');
    });

    it('handles SQL with newlines and tabs', function () {
        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn("SELECT *\n\tFROM users\n\tWHERE id = ?");
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn("SELECT *\n\tFROM users\n\tWHERE id = 1");
        $record->shouldReceive('update')->once()->andReturn(true);

        DB::shouldReceive('connection')->with('mysql')->andReturnSelf();
        DB::shouldReceive('getSchemaBuilder')->andReturnSelf();
        DB::shouldReceive('getColumnListing')->with('users')->andReturn(['id']);
        DB::shouldReceive('getIndexes')->with('users')->andReturn([]);
        DB::shouldReceive('getColumnType')->andReturn('integer');

        config(['slower.recommendation_use_explain' => false]);

        $this->aiService->shouldReceive('analyze')->once()->andReturn('Handle whitespace');

        $result = $this->recommendationService->getRecommendation($record);

        expect($result)->toBe('Handle whitespace');
    });

    it('handles extremely long SQL query', function () {
        $longColumns = implode(', ', array_map(fn ($i) => "column_$i", range(1, 100)));
        $longSql = "SELECT $longColumns FROM users WHERE id IN (".implode(', ', range(1, 1000)).')';

        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn($longSql);
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn($longSql);
        $record->shouldReceive('update')->once()->andReturn(true);

        DB::shouldReceive('connection')->with('mysql')->andReturnSelf();
        DB::shouldReceive('getSchemaBuilder')->andReturnSelf();
        DB::shouldReceive('getColumnListing')->with('users')->andReturn(['id']);
        DB::shouldReceive('getIndexes')->with('users')->andReturn([]);
        DB::shouldReceive('getColumnType')->andReturn('integer');

        config(['slower.recommendation_use_explain' => false]);

        $this->aiService->shouldReceive('analyze')->once()->andReturn('Query too long, consider refactoring');

        $result = $this->recommendationService->getRecommendation($record);

        expect($result)->toBe('Query too long, consider refactoring');
    });

    it('handles SQL with special characters', function () {
        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn("SELECT * FROM users WHERE name = ?");
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn("SELECT * FROM users WHERE name = 'O\\'Reilly & Sons'");
        $record->shouldReceive('update')->once()->andReturn(true);

        DB::shouldReceive('connection')->with('mysql')->andReturnSelf();
        DB::shouldReceive('getSchemaBuilder')->andReturnSelf();
        DB::shouldReceive('getColumnListing')->with('users')->andReturn(['id', 'name']);
        DB::shouldReceive('getIndexes')->with('users')->andReturn([]);
        DB::shouldReceive('getColumnType')->andReturn('varchar');

        config(['slower.recommendation_use_explain' => false]);

        $this->aiService->shouldReceive('analyze')->once()->andReturn('Handle special chars');

        $result = $this->recommendationService->getRecommendation($record);

        expect($result)->toBe('Handle special chars');
    });

    it('handles SQL with case-insensitive keywords', function () {
        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn('sElEcT * fRoM users WhErE id = ?');
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn('sElEcT * fRoM users WhErE id = 1');
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

    it('handles SQL with multiple joins', function () {
        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn(
            'SELECT * FROM users
            JOIN orders ON users.id = orders.user_id
            JOIN products ON orders.product_id = products.id
            JOIN categories ON products.category_id = categories.id'
        );
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn(
            'SELECT * FROM users
            JOIN orders ON users.id = orders.user_id
            JOIN products ON orders.product_id = products.id
            JOIN categories ON products.category_id = categories.id'
        );
        $record->shouldReceive('update')->once()->andReturn(true);

        DB::shouldReceive('connection')->with('mysql')->andReturnSelf();
        DB::shouldReceive('getSchemaBuilder')->andReturnSelf();
        DB::shouldReceive('getColumnListing')->andReturn(['id']);
        DB::shouldReceive('getIndexes')->andReturn([]);
        DB::shouldReceive('getColumnType')->andReturn('integer');

        config(['slower.recommendation_use_explain' => false]);

        $this->aiService->shouldReceive('analyze')->once()->andReturn('Optimize multiple joins');

        $result = $this->recommendationService->getRecommendation($record);

        expect($result)->toBe('Optimize multiple joins');
    });

    it('handles SQL with UNION', function () {
        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn('SELECT * FROM users UNION SELECT * FROM admins');
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn('SELECT * FROM users UNION SELECT * FROM admins');
        $record->shouldReceive('update')->once()->andReturn(true);

        DB::shouldReceive('connection')->with('mysql')->andReturnSelf();
        DB::shouldReceive('getSchemaBuilder')->andReturnSelf();
        DB::shouldReceive('getColumnListing')->with('users')->andReturn(['id']);
        DB::shouldReceive('getColumnListing')->with('admins')->andReturn(['id']);
        DB::shouldReceive('getIndexes')->andReturn([]);
        DB::shouldReceive('getColumnType')->andReturn('integer');

        config(['slower.recommendation_use_explain' => false]);

        $this->aiService->shouldReceive('analyze')->once()->andReturn('Optimize union query');

        $result = $this->recommendationService->getRecommendation($record);

        expect($result)->toBe('Optimize union query');
    });

    it('handles SQL with complex WHERE conditions', function () {
        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn(
            'SELECT * FROM users WHERE (status = ? AND role = ?) OR (created_at > ? AND email LIKE ?)'
        );
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn(
            'SELECT * FROM users WHERE (status = "active" AND role = "admin") OR (created_at > "2024-01-01" AND email LIKE "%@example.com")'
        );
        $record->shouldReceive('update')->once()->andReturn(true);

        DB::shouldReceive('connection')->with('mysql')->andReturnSelf();
        DB::shouldReceive('getSchemaBuilder')->andReturnSelf();
        DB::shouldReceive('getColumnListing')->with('users')->andReturn(['id', 'status', 'role', 'created_at', 'email']);
        DB::shouldReceive('getIndexes')->with('users')->andReturn([]);
        DB::shouldReceive('getColumnType')->andReturn('varchar');

        config(['slower.recommendation_use_explain' => false]);

        $this->aiService->shouldReceive('analyze')->once()->andReturn('Add composite index');

        $result = $this->recommendationService->getRecommendation($record);

        expect($result)->toBe('Add composite index');
    });

    it('handles SQL with GROUP BY and HAVING', function () {
        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn(
            'SELECT user_id, COUNT(*) as total FROM orders GROUP BY user_id HAVING total > ?'
        );
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn(
            'SELECT user_id, COUNT(*) as total FROM orders GROUP BY user_id HAVING total > 10'
        );
        $record->shouldReceive('update')->once()->andReturn(true);

        DB::shouldReceive('connection')->with('mysql')->andReturnSelf();
        DB::shouldReceive('getSchemaBuilder')->andReturnSelf();
        DB::shouldReceive('getColumnListing')->with('orders')->andReturn(['id', 'user_id']);
        DB::shouldReceive('getIndexes')->with('orders')->andReturn([]);
        DB::shouldReceive('getColumnType')->andReturn('integer');

        config(['slower.recommendation_use_explain' => false]);

        $this->aiService->shouldReceive('analyze')->once()->andReturn('Optimize aggregation');

        $result = $this->recommendationService->getRecommendation($record);

        expect($result)->toBe('Optimize aggregation');
    });
});

describe('Table Name Parsing Edge Cases', function () {
    it('handles table names with schema prefix', function () {
        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('pgsql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('pgsql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn('SELECT * FROM public.users');
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn('SELECT * FROM public.users');
        $record->shouldReceive('update')->once()->andReturn(true);

        DB::shouldReceive('connection')->with('pgsql')->andReturnSelf();
        DB::shouldReceive('getSchemaBuilder')->andReturnSelf();
        DB::shouldReceive('getColumnListing')->with('public.users')->andReturn(['id']);
        DB::shouldReceive('getIndexes')->with('public.users')->andReturn([]);
        DB::shouldReceive('getColumnType')->andReturn('integer');

        config(['slower.recommendation_use_explain' => false]);

        $aiService = Mockery::mock(AiServiceDriver::class);
        $aiService->shouldReceive('analyze')->once()->andReturn('Recommendation');

        $recommendationService = new RecommendationService($aiService);
        $result = $recommendationService->getRecommendation($record);

        expect($result)->toBe('Recommendation');
    });

    it('handles table names with numbers', function () {
        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn('SELECT * FROM users_2024');
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn('SELECT * FROM users_2024');
        $record->shouldReceive('update')->once()->andReturn(true);

        DB::shouldReceive('connection')->with('mysql')->andReturnSelf();
        DB::shouldReceive('getSchemaBuilder')->andReturnSelf();
        DB::shouldReceive('getColumnListing')->with('users_2024')->andReturn(['id']);
        DB::shouldReceive('getIndexes')->with('users_2024')->andReturn([]);
        DB::shouldReceive('getColumnType')->andReturn('integer');

        config(['slower.recommendation_use_explain' => false]);

        $aiService = Mockery::mock(AiServiceDriver::class);
        $aiService->shouldReceive('analyze')->once()->andReturn('Recommendation');

        $recommendationService = new RecommendationService($aiService);
        $result = $recommendationService->getRecommendation($record);

        expect($result)->toBe('Recommendation');
    });

    it('handles table names with underscores', function () {
        $record = Mockery::mock(SlowLog::class);
        $record->shouldReceive('getAttribute')->with('time')->andReturn(5000);
        $record->shouldReceive('getAttribute')->with('connection')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('connection_name')->andReturn('mysql');
        $record->shouldReceive('getAttribute')->with('sql')->andReturn('SELECT * FROM user_login_history');
        $record->shouldReceive('getAttribute')->with('raw_sql')->andReturn('SELECT * FROM user_login_history');
        $record->shouldReceive('update')->once()->andReturn(true);

        DB::shouldReceive('connection')->with('mysql')->andReturnSelf();
        DB::shouldReceive('getSchemaBuilder')->andReturnSelf();
        DB::shouldReceive('getColumnListing')->with('user_login_history')->andReturn(['id']);
        DB::shouldReceive('getIndexes')->with('user_login_history')->andReturn([]);
        DB::shouldReceive('getColumnType')->andReturn('integer');

        config(['slower.recommendation_use_explain' => false]);

        $aiService = Mockery::mock(AiServiceDriver::class);
        $aiService->shouldReceive('analyze')->once()->andReturn('Recommendation');

        $recommendationService = new RecommendationService($aiService);
        $result = $recommendationService->getRecommendation($record);

        expect($result)->toBe('Recommendation');
    });
});
