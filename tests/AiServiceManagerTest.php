<?php

use HalilCosdu\Slower\AiServiceDrivers\AiServiceManager;
use HalilCosdu\Slower\AiServiceDrivers\Contracts\AiServiceDriver;
use HalilCosdu\Slower\AiServiceDrivers\PrismDriver;
use Prism\Prism\Enums\Provider;

function driverProp(object $driver, string $prop): mixed
{
    $reflection = new ReflectionProperty($driver, $prop);

    return $reflection->getValue($driver);
}

describe('AiServiceManager provider resolution', function () {
    it('resolves the default provider from config as a PrismDriver', function () {
        config(['slower.ai_service' => 'openai']);

        expect(app(AiServiceManager::class)->driver())->toBeInstanceOf(PrismDriver::class);
    });

    it('maps each blessed provider to the right Prism provider and default model', function () {
        $cases = [
            'openai' => [Provider::OpenAI, 'gpt-5.4-mini'],
            'anthropic' => [Provider::Anthropic, 'claude-haiku-4-5'],
            'gemini' => [Provider::Gemini, 'gemini-2.5-flash'],
        ];

        foreach ($cases as $service => [$provider, $model]) {
            $driver = app(AiServiceManager::class)->driver($service);

            expect($driver)->toBeInstanceOf(PrismDriver::class)
                ->and(driverProp($driver, 'provider'))->toBe($provider)
                ->and(driverProp($driver, 'model'))->toBe($model);
        }
    });

    it('resolves any other Prism provider (e.g. ollama) when a model is configured', function () {
        config(['slower.recommendation_model' => 'qwen2.5-coder']);

        $driver = app(AiServiceManager::class)->driver('ollama');

        expect($driver)->toBeInstanceOf(PrismDriver::class)
            ->and(driverProp($driver, 'provider'))->toBe(Provider::Ollama)
            ->and(driverProp($driver, 'model'))->toBe('qwen2.5-coder');
    });

    it('requires an explicit model for a provider that has no built-in default', function () {
        config(['slower.recommendation_model' => null]);

        expect(fn () => app(AiServiceManager::class)->driver('ollama'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('lets an explicit recommendation_model win for any provider', function () {
        config(['slower.recommendation_model' => 'claude-opus-4-8']);

        expect(driverProp(app(AiServiceManager::class)->driver('anthropic'), 'model'))
            ->toBe('claude-opus-4-8');
    });

    it('treats an empty model as "use the provider default"', function () {
        config(['slower.recommendation_model' => '']);

        expect(driverProp(app(AiServiceManager::class)->driver('gemini'), 'model'))->toBe('gemini-2.5-flash');
    });

    it('passes the configured prompt through as the system prompt', function () {
        config(['slower.prompt' => 'You are a DB expert.']);

        expect(driverProp(app(AiServiceManager::class)->driver('openai'), 'systemPrompt'))
            ->toBe('You are a DB expert.');
    });

    it('throws for a service that is neither a Prism provider nor an extended driver', function () {
        expect(fn () => app(AiServiceManager::class)->driver('not-a-provider'))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('AiServiceManager custom drivers', function () {
    it('keeps extend() registrations across resolutions via the shared manager', function () {
        $custom = Mockery::mock(AiServiceDriver::class);

        // extend() and the actual resolution happen through *separate* app()
        // lookups — exactly the production path (a service provider extends,
        // the AiServiceDriver singleton later resolves).
        app(AiServiceManager::class)->extend('my-llm', fn () => $custom);
        config(['slower.ai_service' => 'my-llm']);

        expect(app(AiServiceDriver::class))->toBe($custom);
    });

    it('honors a create{Name}Driver() method on a subclass (backward compat)', function () {
        $manager = new class(app()) extends AiServiceManager
        {
            public function createFooDriver(): AiServiceDriver
            {
                return new class implements AiServiceDriver
                {
                    public function analyze(string $userMessage): ?string
                    {
                        return 'from create{Name}Driver';
                    }
                };
            }
        };

        expect($manager->driver('foo')->analyze('x'))->toBe('from create{Name}Driver');
    });
});
