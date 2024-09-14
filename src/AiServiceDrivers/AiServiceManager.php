<?php

namespace HalilCosdu\Slower\AiServiceDrivers;

use GuzzleHttp\Client;
use Illuminate\Support\Manager;
use InvalidArgumentException;
use OpenAI as OpenAIFactory;

class AiServiceManager extends Manager
{
    public function createOpenaiDriver(): OpenAiDriver
    {
        $apiKey = config('slower.open_ai.api_key');
        $organization = config('slower.open_ai.organization');
        $timeout = config('slower.open_ai.request_timeout', 30);
        if (! is_string($apiKey) || ($organization !== null && ! is_string($organization))) {
            throw new InvalidArgumentException(
                'The OpenAI API Key is missing. Please publish the [slower.php] configuration file and set the [api_key].'
            );
        }
        $openAI = OpenAIFactory::factory()
            ->withApiKey($apiKey)
            ->withOrganization($organization)
            ->withHttpHeader('OpenAI-Beta', 'assistants=v2')
            ->withHttpClient(new Client(['timeout' => $timeout]))
            ->make();

        return new OpenAiDriver($openAI);
    }

    public function getDefaultDriver(): string
    {
        return config('slower.ai_service', 'openai');
    }
}
