<?php

use HalilCosdu\Slower\Services\ExecutionContext;

arch('the package ships no leftover debugging statements')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'var_export'])
    ->not->toBeUsed();

arch('the package is free of PHP smells')
    ->preset()
    ->php()
    // ExecutionContext deliberately reads the backtrace (IGNORE_ARGS, only on
    // threshold-exceeding queries) to attribute a capture to app code.
    ->ignoring(ExecutionContext::class);
