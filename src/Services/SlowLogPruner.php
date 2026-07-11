<?php

namespace HalilCosdu\Slower\Services;

class SlowLogPruner
{
    /**
     * Delete captured slow logs older than the given number of days
     * (0 deletes everything) and return how many were removed.
     */
    public function olderThan(int $days): int
    {
        $model = config('slower.resources.model');
        $cutoff = now()->subDays($days);

        $deleted = $model::query()->where('created_at', '<', $cutoff)->count();

        $model::query()
            ->where('created_at', '<', $cutoff)
            ->chunkById(1000, fn ($logs) => $logs->each->delete());

        return $deleted;
    }
}
