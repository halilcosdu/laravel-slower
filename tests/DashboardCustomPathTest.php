<?php

namespace HalilCosdu\Slower\Tests;

use Illuminate\Support\Facades\Gate;

class DashboardCustomPathTest extends TestCase
{
    public function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('slower.dashboard.path', '/admin/slow-queries/');
    }

    public function test_serves_the_dashboard_under_the_configured_path(): void
    {
        Gate::define('viewSlower', fn ($user = null) => true);

        $this->assertSame('/admin/slow-queries', route('slower.index', absolute: false));

        $this->get('/admin/slow-queries')->assertOk();
        $this->get('/slower')->assertNotFound();
    }
}
