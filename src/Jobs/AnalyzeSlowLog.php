<?php

namespace HalilCosdu\Slower\Jobs;

use HalilCosdu\Slower\Services\RecommendationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Analyzes one captured query on the queue so a 30s LLM round-trip never
 * blocks a dashboard request or a scheduler tick. Unique per record: while
 * one analysis is queued or running, duplicate dispatches are dropped.
 *
 * Failures follow the queue's own retry semantics; the record itself only
 * becomes `is_analyzed` when a recommendation was actually stored, so it
 * stays retryable either way.
 */
class AnalyzeSlowLog implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Seconds the uniqueness lock is held at most. */
    public int $uniqueFor = 600;

    public function __construct(public Model $record) {}

    public function uniqueId(): string
    {
        return (string) $this->record->getKey();
    }

    public function handle(RecommendationService $service): void
    {
        $service->getRecommendation($this->record);
    }
}
