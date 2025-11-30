<?php

describe('Config', function () {
    it('has default threshold value', function () {
        expect(config('slower.threshold'))->toBe(10000);
    });

    it('has default ai_service value', function () {
        expect(config('slower.ai_service'))->toBe('openai');
    });

    it('has default recommendation_model value', function () {
        expect(config('slower.recommendation_model'))->toBe('gpt-4');
    });

    it('has default enabled value', function () {
        expect(config('slower.enabled'))->toBeTrue();
    });

    it('has default ai_recommendation value', function () {
        expect(config('slower.ai_recommendation'))->toBeTrue();
    });

    it('has default ignore_explain_queries value', function () {
        expect(config('slower.ignore_explain_queries'))->toBeTrue();
    });

    it('has default ignore_insert_queries value', function () {
        expect(config('slower.ignore_insert_queries'))->toBeTrue();
    });

    it('has default recommendation_use_explain value', function () {
        expect(config('slower.recommendation_use_explain'))->toBeTrue();
    });

    it('has resources configuration', function () {
        expect(config('slower.resources.table_name'))->not->toBeNull();
        expect(config('slower.resources.model'))->toBe(\HalilCosdu\Slower\Models\SlowLog::class);
    });

    it('has open_ai configuration keys', function () {
        expect(config('slower.open_ai'))->toHaveKeys(['api_key', 'organization', 'request_timeout']);
    });

    it('has prompt configuration', function () {
        expect(config('slower.prompt'))->toBeString();
        expect(config('slower.prompt'))->toContain('database optimization');
    });
});
