<?php

use HalilCosdu\Slower\Commands\AnalyzeQuery;
use HalilCosdu\Slower\Commands\SlowLogCleaner;

describe('AnalyzeQuery command', function () {
    it('has correct signature', function () {
        $command = new AnalyzeQuery;
        expect($command->signature)->toBe('slower:analyze');
    });

    it('has correct description', function () {
        $command = new AnalyzeQuery;
        expect($command->description)->toBe('Analyze and generate a recommendation for the given record.');
    });
});

describe('SlowLogCleaner command', function () {
    it('has correct signature', function () {
        $command = new SlowLogCleaner;
        expect($command->signature)->toBe('slower:clean {days=15}');
    });

    it('has correct description', function () {
        $command = new SlowLogCleaner;
        expect($command->description)->toBe('Delete records older than 15 days.');
    });
});
