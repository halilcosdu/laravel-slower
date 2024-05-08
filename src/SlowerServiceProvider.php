<?php

namespace HalilCosdu\Slower;

use HalilCosdu\Slower\Commands\AnalyzeQuery;
use HalilCosdu\Slower\Commands\SlowLogCleaner;
use HalilCosdu\Slower\Services\RecommendationService;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenAI as OpenAIFactory;
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
            ->publishesServiceProvider(SlowerServiceProvider::class)
            ->hasCommands(SlowLogCleaner::class, AnalyzeQuery::class);
    }

    public function packageRegistered(): void
    {
        $this->registerDatabaseListener();

        $this->app->singleton(RecommendationService::class, function () {
            $apiKey = config('slower.open_ai.api_key');
            $organization = config('slower.open_ai.organization');
            $timeout = config('slower.open_ai.request_timeout', 30);

            if (! is_string($apiKey) || ($organization !== null && ! is_string($organization))) {
                throw new InvalidArgumentException(
                    'The OpenAI API Key is missing. Please publish the [slower.php] configuration file and set the [api_key].'
                );
            }

            $openAI = OpenAIFactory::factory()
                ->withApiKey($apiKey)
                ->withOrganization($organization)
                ->withHttpHeader('OpenAI-Beta', 'assistants=v1')
                ->withHttpClient(new \GuzzleHttp\Client(['timeout' => $timeout]))
                ->make();

            return new RecommendationService($openAI);
        });
    }

    private function registerDatabaseListener(): void
    {
        if (config('slower.enabled')) {
            DB::whenQueryingForLongerThan(config('slower.threshold', 10000), function (Connection $connection, QueryExecuted $event) {
                $this->createRecord($event, $connection);
                $this->notify($event, $connection);
            });
        }
    }

    private function createRecord(QueryExecuted $event, Connection $connection): void
    {
        $model = config('slower.resources.model');

        if (Str::contains($event->sql, [(new $model)->getTable()])) {
            return;
        }

        (new $model)::query()->create([
            'bindings' => $event->bindings,
            'sql' => $event->sql,
            'time' => $event->time,
            'connection' => get_class($event->connection),
            'connection_name' => $event->connectionName,
            'raw_sql' => $connection->getQueryGrammar()->substituteBindingsIntoRawSql($event->sql, $event->bindings),
        ]);
    }

    private function notify(QueryExecuted $event, Connection $connection)
    {
        //
    }
}
