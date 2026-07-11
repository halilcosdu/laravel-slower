<?php

namespace HalilCosdu\Slower;

use HalilCosdu\Slower\AiServiceDrivers\AiServiceManager;
use HalilCosdu\Slower\AiServiceDrivers\Contracts\AiServiceDriver;
use HalilCosdu\Slower\Commands\AnalyzeQuery;
use HalilCosdu\Slower\Commands\SlowLogCleaner;
use HalilCosdu\Slower\Http\Middleware\Authorize;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
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
            ->hasMigration('create_slower_table')
            ->hasCommands(SlowLogCleaner::class, AnalyzeQuery::class);
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
        $this->registerDatabaseListener();
        $this->app->singleton(
            AiServiceDriver::class,
            fn () => app(AiServiceManager::class)->driver(config('slower.ai_service', 'openai'))
        );
    }

    private function registerDatabaseListener(): void
    {
        if (config('slower.enabled')) {
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

                $this->createRecord($event, $event->connection);
            });
        }
    }

    private function createRecord(QueryExecuted $event, Connection $connection): void
    {
        $model = config('slower.resources.model');

        if (Str::contains($event->sql, config('slower.resources.table_name'))) {
            return;
        }

        $bindings = $this->normalizeBindings($event->bindings);

        try {
            $model::query()->create([
                'bindings' => $bindings,
                'sql' => $event->sql,
                'time' => $event->time,
                'connection' => $event->connection::class,
                'connection_name' => $event->connectionName,
                'raw_sql' => $connection->getQueryGrammar()->substituteBindingsIntoRawSql($event->sql, $bindings),
            ]);
        } catch (\Throwable $e) {
            // Logging slow queries must never break the application's own request.
            // We surface the failure (without the raw SQL, which may contain sensitive data).
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
