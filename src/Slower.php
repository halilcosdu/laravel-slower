<?php

namespace HalilCosdu\Slower;

use HalilCosdu\Slower\Services\RecommendationService;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class Slower
{
    public function __construct(protected RecommendationService $recommendationService)
    {
        //
    }

    public function analyze(Model $record): Model
    {
        $model = config('slower.resources.model');

        if (! $record instanceof $model) {
            throw new InvalidArgumentException('Model must be an instance of '.$model);
        }

        if (config('slower.ai_recommendation')) {
            $this->recommendationService->getRecommendation($record);
        }

        return $record;
    }
}
