<?php

use HalilCosdu\Slower\Services\RecommendationService;
use HalilCosdu\Slower\Slower;

beforeEach(function () {
    $this->mockedRecommendationService = Mockery::mock(RecommendationService::class);
    $this->slower = new Slower($this->mockedRecommendationService);
});
describe('analyze', function () {
    it('throws an exception if the model is not an instance of the configured model', function () {
        class TestModel extends Illuminate\Database\Eloquent\Model {}
        $mockedModel = Mockery::mock(TestModel::class);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Model must be an instance of '.config('slower.resources.model'));
        $this->slower->analyze($mockedModel);
    });

    it('analyzes a model', function () {
        $mockedModel = Mockery::mock(config('slower.resources.model'));

        $expectedResult = $mockedModel;

        $this->mockedRecommendationService->shouldReceive('getRecommendation')->once()->with($mockedModel);

        $result = $this->slower->analyze($mockedModel);

        expect($result)->toBe($expectedResult);
    });

    it('does not call the recommendation service if AI recommendation is disabled', function () {
        config(['slower.ai_recommendation' => false]);
        $mockedModel = Mockery::mock(config('slower.resources.model'));
        $this->mockedRecommendationService->shouldReceive('getRecommendation')->never();
        $result = $this->slower->analyze($mockedModel);
        expect($result)->toBe($mockedModel);
    });

    it('returns the analyzed model', function () {
        config(['slower.ai_recommendation' => true]);
        $mockedModel = Mockery::mock(config('slower.resources.model'));
        $expectedResult = $mockedModel;
        $this->mockedRecommendationService->shouldReceive('getRecommendation')->once()->with($mockedModel);
        $result = $this->slower->analyze($mockedModel);
        expect($result)->toBe($expectedResult);
    });
});
