<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

describe('dashboard routes', function () {
    it('registers all named dashboard routes when enabled', function () {
        foreach ([
            'slower.index',
            'slower.show',
            'slower.analyze',
            'slower.analyze-pending',
            'slower.destroy',
            'slower.clean',
        ] as $name) {
            expect(Route::has($name))->toBeTrue("Route [{$name}] is not registered.");
        }
    });

    it('does not shadow static routes with the {log} wildcard', function () {
        Gate::define('viewSlower', fn ($user = null) => true);

        $this->post(route('slower.analyze-pending'))
            ->assertRedirect();
    });
});

describe('dashboard authorization', function () {
    it('denies access when no gate is defined outside the local environment', function () {
        $this->get(route('slower.index'))->assertForbidden();
    });

    it('denies access when the gate rejects the user', function () {
        Gate::define('viewSlower', fn ($user = null) => false);

        $this->get(route('slower.index'))->assertForbidden();
    });

    it('allows access when the gate accepts the user', function () {
        Gate::define('viewSlower', fn ($user = null) => true);

        $this->get(route('slower.index'))->assertOk();
    });

    it('allows guest access in the local environment by default', function () {
        $this->app['env'] = 'local';

        $this->get(route('slower.index'))->assertOk();
    });

    it('guards every dashboard route with the gate', function () {
        $this->get(route('slower.show', 1))->assertForbidden();
        $this->post(route('slower.analyze', 1))->assertForbidden();
        $this->post(route('slower.analyze-pending'))->assertForbidden();
        $this->delete(route('slower.destroy', 1))->assertForbidden();
        $this->delete(route('slower.clean'))->assertForbidden();
    });
});
