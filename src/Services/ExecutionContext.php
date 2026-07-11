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

    /** @var list<string> LIFO stack of running job classes (nested dispatchSync). */
    private array $jobs = [];

    /** @var list<string> LIFO stack of running artisan commands (nested Artisan::call). */
    private array $commands = [];

    private float $suspendedUntil = 0.0;

    public function startRequest(): void
    {
        $this->captures = 0;
    }

    public function startJob(string $jobClass): void
    {
        $this->jobs[] = $jobClass;
        $this->captures = 0;
    }

    public function endJob(): void
    {
        array_pop($this->jobs);
    }

    public function startCommand(?string $commandName): void
    {
        // A null command name (some framework internals) shouldn't push a frame.
        if ($commandName !== null) {
            $this->commands[] = $commandName;
        }
        $this->captures = 0;
    }

    public function endCommand(): void
    {
        array_pop($this->commands);
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
            $this->jobs !== [] => ['type' => 'queue', 'job' => end($this->jobs)],
            $this->commands !== [] => ['type' => 'console', 'command' => end($this->commands)],
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
