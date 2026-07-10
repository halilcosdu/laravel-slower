<?php

namespace Workbench\App\Providers;

use HalilCosdu\Slower\AiServiceDrivers\AiServiceManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Workbench\App\AiServiceDrivers\FakeAiDriver;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(
            AiServiceManager::class,
            fn (AiServiceManager $manager) => $manager->extend('fake', fn () => new FakeAiDriver)
        );
    }

    public function boot(): void
    {
        Gate::define('viewSlower', fn ($user = null) => true);
    }
}
