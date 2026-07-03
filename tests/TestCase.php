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
        // A fake key so the OpenAI driver can be resolved during tests without
        // making any real HTTP calls.
        config()->set('slower.open_ai.api_key', 'test-key');

        // Ensure the slow log table exists for behavior tests that touch the DB.
        $migration = include __DIR__.'/../database/migrations/create_slower_table.php.stub';
        $migration->up();
    }
}
