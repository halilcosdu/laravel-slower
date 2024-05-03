<?php

use HalilCosdu\Slower\Services\RecommendationService;
use HalilCosdu\Slower\Slower;

beforeEach(function () {
    $this->mockedRecommendationService = Mockery::mock(RecommendationService::class);
    $this->slower = new Slower($this->mockedRecommendationService);
});

it('analyzes a model', function () {
    $mockedModel = Mockery::mock(config('slower.resources.model'));

    $expectedResult = $mockedModel;

    $this->mockedRecommendationService->shouldReceive('getRecommendation')->once()->with($mockedModel);

    $result = $this->slower->analyze($mockedModel);

    expect($result)->toBe($expectedResult);
});
