<?php

namespace HalilCosdu\Slower\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \HalilCosdu\Slower\Slower
 */
class Slower extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \HalilCosdu\Slower\Slower::class;
    }
}
