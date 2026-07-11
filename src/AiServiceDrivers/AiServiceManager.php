<?php

namespace HalilCosdu\Slower\AiServiceDrivers;

use Illuminate\Support\Manager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Prism\Prism\Enums\Provider;

class AiServiceManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return config('slower.ai_service', 'openai');
    }

    /**
     * Resolve a driver by name. Precedence:
     *   1. a driver registered with extend() (custom LLMs),
     *   2. a create{Name}Driver() method (the classic Manager convention),
     *   3. any Prism provider (openai, anthropic, gemini, ollama, …).
     */
    protected function createDriver($driver)
    {
        if (isset($this->customCreators[$driver])) {
            return $this->callCustomCreator($driver);
        }

        $method = 'create'.Str::studly($driver).'Driver';

        if ($method !== 'createDriver' && method_exists($this, $method)) {
            return $this->$method();
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
     * An explicit slower.recommendation_model wins for every provider. Otherwise
     * fall back to a low-cost default for the three first-class providers; any
     * other provider (ollama, groq, a self-hosted model, …) must name its model.
     */
    protected function resolveModel(string $provider): string
    {
        $configured = config('slower.recommendation_model');

        if (is_string($configured) && $configured !== '' && $configured !== 'auto') {
            return $configured;
        }

        return match ($provider) {
            'openai' => 'gpt-5.4-mini',
            'anthropic' => 'claude-haiku-4-5',
            'gemini' => 'gemini-2.5-flash',
            default => throw new InvalidArgumentException(
                "No default model for AI provider [{$provider}]. Set "
                .'slower.recommendation_model (SLOWER_AI_RECOMMENDATION_MODEL).'
            ),
        };
    }
}
