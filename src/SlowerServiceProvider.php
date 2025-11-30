<?php

namespace HalilCosdu\Slower;

use HalilCosdu\Slower\AiServiceDrivers\AiServiceManager;
use HalilCosdu\Slower\AiServiceDrivers\Contracts\AiServiceDriver;
use HalilCosdu\Slower\Commands\AnalyzeQuery;
use HalilCosdu\Slower\Commands\SlowLogCleaner;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
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
            ->hasMigration('create_slower_table')
            ->hasCommands(SlowLogCleaner::class, AnalyzeQuery::class);
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
                $this->notify($event, $event->connection);
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
            (new $model)::query()->create([
                'bindings' => $bindings,
                'sql' => $event->sql,
                'time' => $event->time,
                'connection' => get_class($event->connection),
                'connection_name' => $event->connectionName,
                'raw_sql' => $connection->getQueryGrammar()->substituteBindingsIntoRawSql($event->sql, $bindings),
            ]);
        } catch (\Exception $e) {
            //
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

        /** @phpstan-ignore identical.alwaysFalse */
        if (is_null($bindings)) {
            return [];
        }

        return [$bindings];
    }

    private function notify(QueryExecuted $event, Connection $connection)
    {
        //
    }
}
