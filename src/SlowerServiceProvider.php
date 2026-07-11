<?php

namespace HalilCosdu\Slower;

use HalilCosdu\Slower\AiServiceDrivers\AiServiceManager;
use HalilCosdu\Slower\AiServiceDrivers\Contracts\AiServiceDriver;
use HalilCosdu\Slower\Commands\AnalyzeQuery;
use HalilCosdu\Slower\Commands\FingerprintBackfill;
use HalilCosdu\Slower\Commands\SlowLogCleaner;
use HalilCosdu\Slower\Events\SlowQueryCaptured;
use HalilCosdu\Slower\Events\SlowQueryFirstSeen;
use HalilCosdu\Slower\Http\Middleware\Authorize;
use HalilCosdu\Slower\Services\ExecutionContext;
use HalilCosdu\Slower\Support\SqlFingerprinter;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SlowerServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-slower')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigrations('create_slower_table', 'add_slower_v32_columns')
            ->hasCommands(SlowLogCleaner::class, AnalyzeQuery::class, FingerprintBackfill::class);
    }

    public function packageBooted(): void
    {
        $this->bridgeLegacyOpenAiCredentials();
        $this->registerDashboardGate();
        $this->registerDashboardRoutes();
    }

    /**
     * Backward-compat for pre-3.1 installs: a previously published config still
     * carries a `slower.open_ai.api_key`. Hand it to Prism when Prism's own
     * OpenAI key is unset, so upgrades keep working without editing prism.php.
     */
    private function bridgeLegacyOpenAiCredentials(): void
    {
        $legacyKey = config('slower.open_ai.api_key');

        if (filled($legacyKey) && blank(config('prism.providers.openai.api_key'))) {
            config(['prism.providers.openai.api_key' => $legacyKey]);
        }
    }

    private function registerDashboardGate(): void
    {
        if (! Gate::has('viewSlower')) {
            Gate::define('viewSlower', fn ($user = null) => app()->environment('local'));
        }
    }

    private function registerDashboardRoutes(): void
    {
        if (! config('slower.dashboard.enabled', true)) {
            return;
        }

        Route::group([
            'domain' => config('slower.dashboard.domain'),
            'prefix' => trim((string) config('slower.dashboard.path', 'slower'), '/'),
            'as' => 'slower.',
            'middleware' => config('slower.dashboard.middleware', ['web', Authorize::class]),
        ], fn () => $this->loadRoutesFrom(__DIR__.'/../routes/web.php'));
    }

    public function packageRegistered(): void
    {
        // Shared per-process capture state: origin attribution, the
        // per-execution capture cap, and the capture circuit breaker.
        $this->app->singleton(ExecutionContext::class);

        $this->registerDatabaseListener();

        // Shared so that AiServiceManager::extend() registrations (custom LLMs)
        // survive: the driver binding below resolves the same manager instance.
        $this->app->singleton(AiServiceManager::class);

        $this->app->singleton(
            AiServiceDriver::class,
            fn () => app(AiServiceManager::class)->driver(config('slower.ai_service', 'openai'))
        );
    }

    /**
     * Execution boundaries keep the per-execution counter and the origin
     * attribution correct in long-running processes (queue workers, Octane)
     * as well as classic per-request PHP.
     */
    private function registerExecutionBoundaries(): void
    {
        Event::listen(RouteMatched::class, fn () => $this->app->make(ExecutionContext::class)->startRequest());

        Event::listen(JobProcessing::class, fn (JobProcessing $event) => $this->app->make(ExecutionContext::class)->startJob($event->job->resolveName()));
        Event::listen([JobProcessed::class, JobExceptionOccurred::class], fn () => $this->app->make(ExecutionContext::class)->endJob());

        Event::listen(CommandStarting::class, fn (CommandStarting $event) => $this->app->make(ExecutionContext::class)->startCommand($event->command));
        Event::listen(CommandFinished::class, fn () => $this->app->make(ExecutionContext::class)->endCommand());
    }

    /**
     * How long captures stay suspended after a storage failure. Keeps a
     * broken log table (full disk, dropped table) from adding one failed
     * INSERT to every slow query in the process.
     */
    private const CAPTURE_SUSPEND_SECONDS = 60;

    private function registerDatabaseListener(): void
    {
        if (config('slower.enabled')) {
            $this->registerExecutionBoundaries();

            DB::listen(function (QueryExecuted $event) {
                if ($event->time < config('slower.threshold', 10000)) {
                    return;
                }

                if (config('slower.ignore_explain_queries', true) && Str::startsWith($event->sql, 'EXPLAIN')) {
                    return;
                }

                if (config('slower.ignore_insert_queries', true) && stripos($event->sql, 'insert') === 0) {
                    return;
                }

                $this->captureQuery($event, $event->connection);
            });
        }
    }

    /**
     * Overhead guards, cheapest first: circuit breaker, self-capture, then
     * sampling and the per-execution cap. Only queries that pass them all
     * pay the (rare) cost of fingerprinting, origin resolution and storage.
     */
    private function captureQuery(QueryExecuted $event, Connection $connection): void
    {
        $context = $this->app->make(ExecutionContext::class);

        if ($context->isSuspended()) {
            return;
        }

        if (Str::contains($event->sql, config('slower.resources.table_name'))) {
            return;
        }

        $sampleRate = (float) config('slower.capture.sample_rate', 1.0);

        if ($sampleRate < 1.0 && (mt_rand() / mt_getrandmax()) >= $sampleRate) {
            return;
        }

        $maxPerExecution = config('slower.capture.max_per_execution', 50);

        if ($maxPerExecution !== null && $context->captureCount() >= (int) $maxPerExecution) {
            return;
        }

        $this->createRecord($event, $connection, $context);
    }

    private function createRecord(QueryExecuted $event, Connection $connection, ExecutionContext $context): void
    {
        $model = config('slower.resources.model');
        $bindings = $this->normalizeBindings($event->bindings);
        $fingerprinter = $this->app->make(SqlFingerprinter::class);

        try {
            $record = $model::query()->create([
                'bindings' => $bindings,
                'sql' => $event->sql,
                'time' => $event->time,
                'connection' => $event->connection::class,
                'connection_name' => $event->connectionName,
                'raw_sql' => $connection->getQueryGrammar()->substituteBindingsIntoRawSql($event->sql, $bindings),
                'fingerprint' => $fingerprint = $fingerprinter->fingerprint($event->sql),
                'fingerprint_version' => SqlFingerprinter::VERSION,
                'origin' => $context->origin(),
            ]);

            $context->recordCapture();

            Event::dispatch(new SlowQueryCaptured($record));

            if ($model::query()->where('fingerprint', $fingerprint)->count() === 1) {
                Event::dispatch(new SlowQueryFirstSeen($record));
            }
        } catch (\Throwable $e) {
            // Logging slow queries must never break the application's own
            // request — and must not keep re-failing on every subsequent
            // query either, hence the temporary suspension. The failure is
            // surfaced without the raw SQL (it may contain sensitive data).
            $context->suspendFor(self::CAPTURE_SUSPEND_SECONDS);
            report(new \RuntimeException('Failed to store slow query log: '.$e->getMessage(), 0, $e));
        }
    }

    /**
     * Normalize bindings to array format.
     *
     * Laravel documents QueryExecuted::$bindings as array, but some custom
     * drivers or special queries may provide string or null values.
     *
     * @return array<int, mixed>
     */
    private function normalizeBindings(mixed $bindings): array
    {
        if (is_array($bindings)) {
            return $bindings;
        }

        if (is_null($bindings)) {
            return [];
        }

        return [$bindings];
    }
}
