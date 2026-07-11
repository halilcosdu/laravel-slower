<?php

use HalilCosdu\Slower\Services\ExecutionContext;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Route;

class ExecutionContextTestUser extends AuthUser
{
    protected $guarded = [];
}

describe('origin resolution', function () {
    it('returns null when origin capture is disabled', function () {
        config(['slower.capture.origin.enabled' => false]);

        expect(app(ExecutionContext::class)->origin())->toBeNull();
    });

    it('describes an HTTP request with route name, action and uri pattern', function () {
        Route::middleware('web')->get('/origin-probe', function () {
            return response()->json(app(ExecutionContext::class)->origin());
        })->name('origin.probe');

        $origin = $this->getJson('/origin-probe')->assertOk()->json();

        expect($origin['type'])->toBe('http')
            ->and($origin['route'])->toBe('origin.probe')
            ->and($origin['uri'])->toBe('origin-probe')
            ->and($origin['action'])->toBe('Closure')
            ->and($origin)->not->toHaveKey('user_id');
    });

    it('includes the authenticated user id only when opted in', function () {
        config(['slower.capture.origin.user_id' => true]);

        Route::middleware('web')->get('/origin-user', function () {
            return response()->json(app(ExecutionContext::class)->origin());
        });

        $user = new ExecutionContextTestUser;
        $user->id = 77;

        $origin = $this->actingAs($user)->getJson('/origin-user')->json();

        expect($origin['user_id'])->toBe(77);
    });

    it('describes a running artisan command', function () {
        $context = app(ExecutionContext::class);
        $context->startCommand('app:report');

        expect($context->origin())->toMatchArray(['type' => 'console', 'command' => 'app:report']);

        $context->endCommand();

        expect($context->origin()['command'] ?? null)->toBeNull();
    });

    it('describes a queue job and takes precedence over other contexts', function () {
        $context = app(ExecutionContext::class);
        $context->startCommand('queue:work');
        $context->startJob('App\\Jobs\\ProcessReport');

        expect($context->origin())->toMatchArray(['type' => 'queue', 'job' => 'App\\Jobs\\ProcessReport']);

        $context->endJob();

        expect($context->origin()['type'])->toBe('console');
    });

    it('restores the outer job context after a nested job finishes', function () {
        $context = app(ExecutionContext::class);
        $context->startJob('App\\Jobs\\Outer');
        $context->startJob('App\\Jobs\\Inner'); // dispatchSync inside Outer
        $context->endJob();

        // Back inside Outer — not reset to http/console.
        expect($context->origin())->toMatchArray(['type' => 'queue', 'job' => 'App\\Jobs\\Outer']);
    });

    it('restores the outer command context after a nested command finishes', function () {
        $context = app(ExecutionContext::class);
        $context->startCommand('app:outer');
        $context->startCommand('app:inner'); // Artisan::call inside app:outer
        $context->endCommand();

        expect($context->origin())->toMatchArray(['type' => 'console', 'command' => 'app:outer']);
    });

    it('records the first application code frame', function () {
        $origin = app(ExecutionContext::class)->origin();

        // The first frame outside vendor/ and outside the package's src/ is
        // this very test file.
        expect($origin['frame'])->toContain('ExecutionContextTest.php:');
    });
});

describe('per-execution capture counting', function () {
    it('counts captures and resets on a new execution boundary', function () {
        $context = app(ExecutionContext::class);

        $context->recordCapture();
        $context->recordCapture();
        expect($context->captureCount())->toBe(2);

        $context->startRequest();
        expect($context->captureCount())->toBe(0);
    });

    it('resets the counter when a job or command starts', function () {
        $context = app(ExecutionContext::class);

        $context->recordCapture();
        $context->startJob('App\\Jobs\\X');
        expect($context->captureCount())->toBe(0);

        $context->recordCapture();
        $context->startCommand('inspire');
        expect($context->captureCount())->toBe(0);
    });

    it('is a singleton so listener and resolver share state', function () {
        app(ExecutionContext::class)->recordCapture();

        expect(app(ExecutionContext::class)->captureCount())->toBe(1);
    });
});

describe('capture circuit breaker', function () {
    it('suspends captures for a while after a failure', function () {
        $context = app(ExecutionContext::class);

        expect($context->isSuspended())->toBeFalse();

        $context->suspendFor(60);

        expect($context->isSuspended())->toBeTrue();
    });

    it('expires the suspension', function () {
        $context = app(ExecutionContext::class);
        $context->suspendFor(0);

        expect($context->isSuspended())->toBeFalse();
    });
});
