<?php

namespace HalilCosdu\Slower\Tests;

use HalilCosdu\Slower\SlowerServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'HalilCosdu\\Slower\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            SlowerServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        // The dashboard routes ride the `web` group (sessions + encrypted
        // cookies), so an app key must be present — as it is in any real app.
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        // Locks and rate limiting need a lock-capable store without external setup.
        config()->set('cache.default', 'array');
        // A fake key so the OpenAI driver can be resolved during tests without
        // making any real HTTP calls.
        config()->set('slower.open_ai.api_key', 'test-key');

        // Ensure the slow log table exists for behavior tests that touch the DB.
        $migration = include __DIR__.'/../database/migrations/create_slower_table.php.stub';
        $migration->up();
    }
}
