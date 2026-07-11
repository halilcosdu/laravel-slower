<?php

namespace HalilCosdu\Slower\Services;

use Illuminate\Support\Facades\Route;

/**
 * Per-process capture state, bound as a singleton.
 *
 * Tracks what the application is currently executing (HTTP request, queue
 * job, artisan command) so captured queries can be attributed to their
 * origin, enforces the per-execution capture cap, and carries the capture
 * circuit breaker. Execution boundaries (route matched, job starting,
 * command starting) reset the counter, which keeps the state correct in
 * long-running workers (queue, Octane) as well as classic FPM requests.
 */
class ExecutionContext
{
    private int $captures = 0;

    private ?string $jobClass = null;

    private ?string $commandName = null;

    private float $suspendedUntil = 0.0;

    public function startRequest(): void
    {
        $this->captures = 0;
    }

    public function startJob(string $jobClass): void
    {
        $this->jobClass = $jobClass;
        $this->captures = 0;
    }

    public function endJob(): void
    {
        $this->jobClass = null;
    }

    public function startCommand(?string $commandName): void
    {
        $this->commandName = $commandName;
        $this->captures = 0;
    }

    public function endCommand(): void
    {
        $this->commandName = null;
    }

    public function recordCapture(): void
    {
        $this->captures++;
    }

    public function captureCount(): int
    {
        return $this->captures;
    }

    public function suspendFor(int $seconds): void
    {
        $this->suspendedUntil = microtime(true) + $seconds;
    }

    public function isSuspended(): bool
    {
        return microtime(true) < $this->suspendedUntil;
    }

    /**
     * Where is the query we are capturing coming from? Only called for
     * queries that already crossed the slow threshold, so the backtrace cost
     * is paid rarely, and never when origin capture is disabled.
     *
     * @return array<string, mixed>|null
     */
    public function origin(): ?array
    {
        if (! config('slower.capture.origin.enabled', true)) {
            return null;
        }

        $origin = match (true) {
            $this->jobClass !== null => ['type' => 'queue', 'job' => $this->jobClass],
            $this->commandName !== null => ['type' => 'console', 'command' => $this->commandName],
            Route::current() !== null => [
                'type' => 'http',
                'route' => Route::currentRouteName(),
                'uri' => Route::current()->uri(),
                'action' => Route::currentRouteAction() ?? 'Closure',
            ],
            default => ['type' => app()->runningInConsole() ? 'console' : 'http'],
        };

        if ($frame = $this->firstApplicationFrame()) {
            $origin['frame'] = $frame;
        }

        if (config('slower.capture.origin.user_id', false) && ($userId = auth()->id()) !== null) {
            $origin['user_id'] = $userId;
        }

        return array_filter($origin, fn ($value) => $value !== null);
    }

    /**
     * The first stack frame outside vendor/ and outside this package — the
     * application code that triggered the query. Arguments are never
     * collected (DEBUG_BACKTRACE_IGNORE_ARGS), so no query values or PII can
     * leak in via the trace.
     */
    private function firstApplicationFrame(): ?string
    {
        $packageSrc = dirname(__DIR__);
        $basePath = base_path().DIRECTORY_SEPARATOR;
        $vendorSegment = DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR;

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60) as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null || str_contains($file, $vendorSegment) || str_starts_with($file, $packageSrc)) {
                continue;
            }

            $path = str_starts_with($file, $basePath) ? substr($file, strlen($basePath)) : $file;

            return $path.':'.($frame['line'] ?? 0);
        }

        return null;
    }
}
