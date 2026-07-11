<?php

use HalilCosdu\Slower\Models\SlowLog;
use HalilCosdu\Slower\Support\SqlFingerprinter;

describe('slower:fingerprint', function () {
    it('backfills records captured before v3.2', function () {
        $legacy = SlowLog::factory()->count(2)->create([
            'sql' => 'select * from orders where status = ?',
            'fingerprint' => null,
            'fingerprint_version' => null,
        ]);

        $this->artisan('slower:fingerprint')
            ->assertSuccessful()
            ->expectsOutputToContain('Fingerprinted: 2');

        $expected = (new SqlFingerprinter)->fingerprint('select * from orders where status = ?');

        foreach ($legacy as $record) {
            expect($record->refresh())
                ->fingerprint->toBe($expected)
                ->fingerprint_version->toBe(SqlFingerprinter::VERSION);
        }
    });

    it('re-fingerprints records stamped with an older algorithm version', function () {
        $record = SlowLog::factory()->create([
            'fingerprint' => 'stale-fingerprint',
            'fingerprint_version' => 0,
        ]);

        $this->artisan('slower:fingerprint')->assertSuccessful();

        expect($record->refresh())
            ->fingerprint->not->toBe('stale-fingerprint')
            ->fingerprint_version->toBe(SqlFingerprinter::VERSION);
    });

    it('is idempotent and leaves current records untouched', function () {
        SlowLog::factory()->create(); // fingerprinted by the factory

        $this->artisan('slower:fingerprint')
            ->assertSuccessful()
            ->expectsOutputToContain('Fingerprinted: 0');
    });
});
