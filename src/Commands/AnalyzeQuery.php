<?php

namespace HalilCosdu\Slower\Commands;

use HalilCosdu\Slower\Services\RecommendationService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AnalyzeQuery extends Command
{
    public $signature = 'slower:analyze';

    public $description = 'Analyze and generate a recommendation for the given record.';

    public function handle(RecommendationService $recommendationService): int
    {
        if (! config('slower.enabled') || ! config('slower.ai_recommendation')) {
            $this->warn('Slower or AI recommendation is not enabled. Please enable it in the configuration file.');

            return self::SUCCESS;
        }
        $model = config('slower.resources.model');

        $analyzed = 0;
        $skipped = 0;

        $model::query()
            ->where('is_analyzed', false)
            ->chunkById(1000, function ($records) use ($recommendationService, &$analyzed, &$skipped) {
                foreach ($records as $record) {
                    $output = $recommendationService->getRecommendation($record);

                    if ($output === null) {
                        $skipped++;

                        continue;
                    }

                    $analyzed++;
                    $this->info(Str::words($output, 10));
                }
            });

        $this->line('------------------------------------');
        $this->comment('Analyzed: '.$analyzed.' | Skipped (no recommendation, will retry on the next run): '.$skipped);

        return self::SUCCESS;
    }
}
