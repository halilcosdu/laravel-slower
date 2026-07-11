<?php

namespace HalilCosdu\Slower\AiServiceDrivers;

use Illuminate\Support\Manager;
use InvalidArgumentException;
use Prism\Prism\Enums\Provider;

class AiServiceManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return config('slower.ai_service', 'openai');
    }

    /**
     * Resolve any Prism provider by name (openai, anthropic, gemini, ollama, …).
     * A driver registered with extend() always wins, so custom LLMs need no
     * changes here.
     */
    protected function createDriver($driver)
    {
        if (isset($this->customCreators[$driver])) {
            return $this->callCustomCreator($driver);
        }

        $provider = Provider::tryFrom((string) $driver);

        if ($provider === null) {
            throw new InvalidArgumentException(
                "AI service [{$driver}] is not supported. Use a Prism provider "
                .'(e.g. openai, anthropic, gemini, ollama) or register a custom '
                .'driver with AiServiceManager::extend().'
            );
        }

        return new PrismDriver(
            $provider,
            $this->resolveModel($provider->value),
            (string) config('slower.prompt'),
        );
    }

    /**
     * An explicit slower.recommendation_model wins for every provider; otherwise
     * fall back to a sensible, low-cost default per provider.
     */
    protected function resolveModel(string $provider): string
    {
        $configured = config('slower.recommendation_model');

        if (is_string($configured) && $configured !== '' && $configured !== 'auto') {
            return $configured;
        }

        return match ($provider) {
            'anthropic' => 'claude-haiku-4-5',
            'gemini' => 'gemini-2.5-flash',
            default => 'gpt-5.4-mini',
        };
    }
}
