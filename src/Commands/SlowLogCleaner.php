<?php

namespace HalilCosdu\Slower\Commands;

use Illuminate\Console\Command;

class SlowLogCleaner extends Command
{
    public $signature = 'slower-clean {days=15}';

    public $description = 'Delete records older than 15 days.';

    public function handle(): int
    {
        $model = config('slower.resources.model');

        (new $model)::query()
            ->where('created_at', '<', now()->subDays(intval($this->argument('days'))))
            ->chunk(1000, function ($logs) {
                $logs->each->delete();
            });

        $this->comment('All done');

        return self::SUCCESS;
    }
}
