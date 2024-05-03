<?php

namespace HalilCosdu\Slower\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * @see \HalilCosdu\Slower\Slower
 * @method static \HalilCosdu\Slower\Slower analyze(Model $record): Model
 */
class Slower extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \HalilCosdu\Slower\Slower::class;
    }
}
