<?php

use HalilCosdu\Slower\SlowerServiceProvider;

describe('SlowerServiceProvider', function () {
    it('registers the database listener when enabled', function () {
        config(['slower.enabled' => true]);

        $provider = new SlowerServiceProvider(app());
        $provider->register();

        expect(config('slower.enabled'))->toBeTrue();
    });

    it('does not register listener when disabled', function () {
        config(['slower.enabled' => false]);

        expect(config('slower.enabled'))->toBeFalse();
    });
});

describe('normalizeBindings', function () {
    it('handles array bindings correctly', function () {
        $provider = new SlowerServiceProvider(app());
        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('normalizeBindings');
        $method->setAccessible(true);

        $result = $method->invoke($provider, ['value1', 'value2']);
        expect($result)->toBe(['value1', 'value2']);
    });

    it('handles null bindings correctly', function () {
        $provider = new SlowerServiceProvider(app());
        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('normalizeBindings');
        $method->setAccessible(true);

        $result = $method->invoke($provider, null);
        expect($result)->toBe([]);
    });

    it('handles string bindings correctly', function () {
        $provider = new SlowerServiceProvider(app());
        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('normalizeBindings');
        $method->setAccessible(true);

        $result = $method->invoke($provider, 'string_value');
        expect($result)->toBe(['string_value']);
    });

    it('handles integer bindings correctly', function () {
        $provider = new SlowerServiceProvider(app());
        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('normalizeBindings');
        $method->setAccessible(true);

        $result = $method->invoke($provider, 123);
        expect($result)->toBe([123]);
    });

    it('handles empty array bindings correctly', function () {
        $provider = new SlowerServiceProvider(app());
        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('normalizeBindings');
        $method->setAccessible(true);

        $result = $method->invoke($provider, []);
        expect($result)->toBe([]);
    });
});
