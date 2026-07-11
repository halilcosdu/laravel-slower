<?php

namespace HalilCosdu\Slower\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * Fired the first time a query shape (fingerprint) is ever captured — the
 * "a new slow query appeared" signal, without the noise of every repeat.
 */
class SlowQueryFirstSeen
{
    public function __construct(public Model $record) {}
}
