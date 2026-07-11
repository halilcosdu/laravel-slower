<?php

namespace HalilCosdu\Slower\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * Fired after every slow query is stored. Attach a listener (or a Laravel
 * Notification) to forward captures to Slack, mail, or your own tooling —
 * the package deliberately ships no notification channels of its own.
 */
class SlowQueryCaptured
{
    public function __construct(public Model $record) {}
}
