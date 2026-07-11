<?php

namespace HalilCosdu\Slower\Commands;

use HalilCosdu\Slower\Support\SqlFingerprinter;
use Illuminate\Console\Command;

/**
 * Backfills fingerprints for records captured before v3.2, and re-stamps
 * records whose fingerprint was produced by an older algorithm version.
 * Chunked and idempotent, so it can be interrupted and re-run safely on
 * large tables.
 */
class FingerprintBackfill extends Command
{
    public $signature = 'slower:fingerprint';

    public $description = 'Backfill query fingerprints for records captured before v3.2 (or with an older algorithm).';

    public function handle(SqlFingerprinter $fingerprinter): int
    {
        $model = config('slower.resources.model');
        $updated = 0;

        $model::query()
            ->where(function ($query) {
                $query->whereNull('fingerprint')
                    ->orWhere('fingerprint_version', '<', SqlFingerprinter::VERSION);
            })
            ->chunkById(1000, function ($records) use ($fingerprinter, &$updated) {
                foreach ($records as $record) {
                    $record->update([
                        'fingerprint' => $fingerprinter->fingerprint((string) $record->sql),
                        'fingerprint_version' => SqlFingerprinter::VERSION,
                    ]);

                    $updated++;
                }
            });

        $this->comment('Fingerprinted: '.$updated);

        return self::SUCCESS;
    }
}
