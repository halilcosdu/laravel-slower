<?php

namespace HalilCosdu\Slower\AiServiceDrivers;

use HalilCosdu\Slower\AiServiceDrivers\Contracts\AiServiceDriver;
use OpenAI\Client;

class OpenAiDriver implements AiServiceDriver
{
    public function __construct(protected Client $client)
    {
    }

    public function analyze(string $userMessage): ?string
    {
        $result = $this->client->chat()->create([
            'model' => config('slower.recommendation_model', 'gpt-4'),
            'messages' => [
                ['role' => 'system', 'content' => config('slower.prompt')],
                ['role' => 'user', 'content' => $userMessage],
            ],
        ]);

        return $result->choices[0]->message->content ?? null;
    }
}
