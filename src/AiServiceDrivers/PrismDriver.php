<?php

namespace HalilCosdu\Slower\AiServiceDrivers;

use HalilCosdu\Slower\AiServiceDrivers\Contracts\AiServiceDriver;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

/**
 * Single driver for every Prism-backed provider (OpenAI, Anthropic, Gemini,
 * Ollama, and any other OpenAI-compatible/self-hosted endpoint Prism speaks).
 *
 * This is the only class that touches Prism, so the rest of the package stays
 * decoupled from Prism's (pre-1.0) API.
 */
class PrismDriver implements AiServiceDriver
{
    public function __construct(
        protected Provider $provider,
        protected string $model,
        protected string $systemPrompt,
    ) {}

    public function analyze(string $userMessage): ?string
    {
        // Provider/transport failures deliberately propagate: callers already
        // treat exceptions as "retry later", and a null return must mean only
        // "the model produced no usable text" so the record stays retryable.
        $text = Prism::text()
            ->using($this->provider, $this->model)
            ->withSystemPrompt($this->systemPrompt)
            ->withPrompt($userMessage)
            ->asText()
            ->text;

        return filled($text) ? $text : null;
    }
}
