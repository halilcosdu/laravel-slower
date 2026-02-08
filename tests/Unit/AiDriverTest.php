<?php

use HalilCosdu\Slower\AiServiceDrivers\AiServiceManager;
use HalilCosdu\Slower\AiServiceDrivers\OpenAiDriver;
use OpenAI\Client;
use OpenAI\Responses\Chat\CreateResponse;

describe('OpenAiDriver', function () {
    it('returns recommendation from OpenAI', function () {
        $client = Mockery::mock(Client::class);
        $chat = Mockery::mock();
        $response = Mockery::mock(CreateResponse::class);

        $choice = (object) [
            'message' => (object) [
                'content' => 'Add index on user_id column',
            ],
        ];

        $response->choices = [$choice];

        $chat->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($params) {
                return $params['model'] === 'gpt-4'
                    && count($params['messages']) === 2
                    && $params['messages'][0]['role'] === 'system'
                    && $params['messages'][1]['role'] === 'user';
            }))
            ->andReturn($response);

        $client->shouldReceive('chat')->andReturn($chat);

        config(['slower.recommendation_model' => 'gpt-4']);
        config(['slower.prompt' => 'System prompt here']);

        $driver = new OpenAiDriver($client);
        $result = $driver->analyze('Test query');

        expect($result)->toBe('Add index on user_id column');
    });

    it('returns null when OpenAI response is empty', function () {
        $client = Mockery::mock(Client::class);
        $chat = Mockery::mock();
        $response = Mockery::mock(CreateResponse::class);

        $response->choices = [];

        $chat->shouldReceive('create')->once()->andReturn($response);
        $client->shouldReceive('chat')->andReturn($chat);

        $driver = new OpenAiDriver($client);
        $result = $driver->analyze('Test query');

        expect($result)->toBeNull();
    });

    it('returns null when message content is null', function () {
        $client = Mockery::mock(Client::class);
        $chat = Mockery::mock();
        $response = Mockery::mock(CreateResponse::class);

        $choice = (object) [
            'message' => (object) [
                'content' => null,
            ],
        ];

        $response->choices = [$choice];

        $chat->shouldReceive('create')->once()->andReturn($response);
        $client->shouldReceive('chat')->andReturn($chat);

        $driver = new OpenAiDriver($client);
        $result = $driver->analyze('Test query');

        expect($result)->toBeNull();
    });

    it('uses configured model from config', function () {
        $client = Mockery::mock(Client::class);
        $chat = Mockery::mock();
        $response = Mockery::mock(CreateResponse::class);

        $choice = (object) [
            'message' => (object) [
                'content' => 'Response',
            ],
        ];

        $response->choices = [$choice];

        $chat->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($params) {
                return $params['model'] === 'gpt-4-turbo';
            }))
            ->andReturn($response);

        $client->shouldReceive('chat')->andReturn($chat);

        config(['slower.recommendation_model' => 'gpt-4-turbo']);

        $driver = new OpenAiDriver($client);
        $driver->analyze('Test query');
    });

    it('includes system prompt from config', function () {
        $client = Mockery::mock(Client::class);
        $chat = Mockery::mock();
        $response = Mockery::mock(CreateResponse::class);

        $choice = (object) [
            'message' => (object) [
                'content' => 'Response',
            ],
        ];

        $response->choices = [$choice];

        $chat->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($params) {
                return $params['messages'][0]['role'] === 'system'
                    && $params['messages'][0]['content'] === 'Custom system prompt';
            }))
            ->andReturn($response);

        $client->shouldReceive('chat')->andReturn($chat);

        config(['slower.prompt' => 'Custom system prompt']);

        $driver = new OpenAiDriver($client);
        $driver->analyze('Test query');
    });

    it('handles OpenAI API exceptions', function () {
        $client = Mockery::mock(Client::class);
        $chat = Mockery::mock();

        $chat->shouldReceive('create')
            ->once()
            ->andThrow(new \Exception('API rate limit exceeded'));

        $client->shouldReceive('chat')->andReturn($chat);

        $driver = new OpenAiDriver($client);

        expect(fn () => $driver->analyze('Test query'))
            ->toThrow(\Exception::class, 'API rate limit exceeded');
    });

    it('handles network timeout exceptions', function () {
        $client = Mockery::mock(Client::class);
        $chat = Mockery::mock();

        $chat->shouldReceive('create')
            ->once()
            ->andThrow(new \Exception('Connection timeout'));

        $client->shouldReceive('chat')->andReturn($chat);

        $driver = new OpenAiDriver($client);

        expect(fn () => $driver->analyze('Test query'))
            ->toThrow(\Exception::class, 'Connection timeout');
    });

    it('handles authentication errors', function () {
        $client = Mockery::mock(Client::class);
        $chat = Mockery::mock();

        $chat->shouldReceive('create')
            ->once()
            ->andThrow(new \Exception('Invalid API key'));

        $client->shouldReceive('chat')->andReturn($chat);

        $driver = new OpenAiDriver($client);

        expect(fn () => $driver->analyze('Test query'))
            ->toThrow(\Exception::class, 'Invalid API key');
    });

    it('handles malformed response structure', function () {
        $client = Mockery::mock(Client::class);
        $chat = Mockery::mock();
        $response = Mockery::mock(CreateResponse::class);

        $choice = (object) [
            'message' => (object) [],
        ];

        $response->choices = [$choice];

        $chat->shouldReceive('create')->once()->andReturn($response);
        $client->shouldReceive('chat')->andReturn($chat);

        $driver = new OpenAiDriver($client);
        $result = $driver->analyze('Test query');

        expect($result)->toBeNull();
    });
});

describe('AiServiceManager', function () {
    it('creates OpenAI driver by default', function () {
        config(['slower.ai_service' => 'openai']);

        $manager = app(AiServiceManager::class);
        $driver = $manager->driver();

        expect($driver)->toBeInstanceOf(OpenAiDriver::class);
    });

    it('creates OpenAI driver when explicitly requested', function () {
        $manager = app(AiServiceManager::class);
        $driver = $manager->driver('openai');

        expect($driver)->toBeInstanceOf(OpenAiDriver::class);
    });

    it('caches driver instances', function () {
        $manager = app(AiServiceManager::class);
        $driver1 = $manager->driver('openai');
        $driver2 = $manager->driver('openai');

        expect($driver1)->toBe($driver2);
    });

    it('throws exception for unknown driver', function () {
        $manager = app(AiServiceManager::class);

        expect(fn () => $manager->driver('unknown_driver'))
            ->toThrow(\InvalidArgumentException::class);
    });
});

describe('AI Driver Edge Cases', function () {
    it('handles extremely long user messages', function () {
        $client = Mockery::mock(Client::class);
        $chat = Mockery::mock();
        $response = Mockery::mock(CreateResponse::class);

        $choice = (object) [
            'message' => (object) [
                'content' => 'Handled long message',
            ],
        ];

        $response->choices = [$choice];

        $longMessage = str_repeat('SQL query here ', 10000);

        $chat->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($params) use ($longMessage) {
                return $params['messages'][1]['content'] === $longMessage;
            }))
            ->andReturn($response);

        $client->shouldReceive('chat')->andReturn($chat);

        $driver = new OpenAiDriver($client);
        $result = $driver->analyze($longMessage);

        expect($result)->toBe('Handled long message');
    });

    it('handles unicode characters in user message', function () {
        $client = Mockery::mock(Client::class);
        $chat = Mockery::mock();
        $response = Mockery::mock(CreateResponse::class);

        $choice = (object) [
            'message' => (object) [
                'content' => 'Handled unicode',
            ],
        ];

        $response->choices = [$choice];

        $unicodeMessage = 'Query with 中文 and émojis 😀';

        $chat->shouldReceive('create')->once()->andReturn($response);
        $client->shouldReceive('chat')->andReturn($chat);

        $driver = new OpenAiDriver($client);
        $result = $driver->analyze($unicodeMessage);

        expect($result)->toBe('Handled unicode');
    });

    it('handles empty user message', function () {
        $client = Mockery::mock(Client::class);
        $chat = Mockery::mock();
        $response = Mockery::mock(CreateResponse::class);

        $choice = (object) [
            'message' => (object) [
                'content' => 'Response to empty',
            ],
        ];

        $response->choices = [$choice];

        $chat->shouldReceive('create')->once()->andReturn($response);
        $client->shouldReceive('chat')->andReturn($chat);

        $driver = new OpenAiDriver($client);
        $result = $driver->analyze('');

        expect($result)->toBe('Response to empty');
    });

    it('handles special JSON characters in message', function () {
        $client = Mockery::mock(Client::class);
        $chat = Mockery::mock();
        $response = Mockery::mock(CreateResponse::class);

        $choice = (object) [
            'message' => (object) [
                'content' => 'Handled special chars',
            ],
        ];

        $response->choices = [$choice];

        $specialMessage = 'Query with "quotes" and \backslashes\ and {braces}';

        $chat->shouldReceive('create')->once()->andReturn($response);
        $client->shouldReceive('chat')->andReturn($chat);

        $driver = new OpenAiDriver($client);
        $result = $driver->analyze($specialMessage);

        expect($result)->toBe('Handled special chars');
    });

    it('handles newlines and tabs in message', function () {
        $client = Mockery::mock(Client::class);
        $chat = Mockery::mock();
        $response = Mockery::mock(CreateResponse::class);

        $choice = (object) [
            'message' => (object) [
                'content' => 'Handled whitespace',
            ],
        ];

        $response->choices = [$choice];

        $messageWithWhitespace = "Query\nwith\ttabs\nand\nnewlines";

        $chat->shouldReceive('create')->once()->andReturn($response);
        $client->shouldReceive('chat')->andReturn($chat);

        $driver = new OpenAiDriver($client);
        $result = $driver->analyze($messageWithWhitespace);

        expect($result)->toBe('Handled whitespace');
    });
});
