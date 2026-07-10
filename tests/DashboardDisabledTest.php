<?php

namespace HalilCosdu\Slower\Tests;

use Illuminate\Support\Facades\Route;

class DashboardDisabledTest extends TestCase
{
    public function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('slower.dashboard.enabled', false);
    }

    public function test_registers_no_dashboard_routes(): void
    {
        $this->assertFalse(Route::has('slower.index'));
    }

    public function test_returns_404_for_the_dashboard_path(): void
    {
        $this->get('/slower')->assertNotFound();
    }
}
