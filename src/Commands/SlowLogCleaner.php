<?php

namespace HalilCosdu\Slower\Commands;

use HalilCosdu\Slower\Services\SlowLogPruner;
use Illuminate\Console\Command;

class SlowLogCleaner extends Command
{
    public $signature = 'slower:clean {days=15}';

    public $description = 'Delete records older than 15 days.';

    public function handle(SlowLogPruner $pruner): int
    {
        $pruner->olderThan(intval($this->argument('days')));

        $this->comment('All done');

        return self::SUCCESS;
    }
}
