<?php

use HalilCosdu\Slower\AiServiceDrivers\PrismDriver;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;

describe('PrismDriver', function () {
    it('returns the model text and routes provider, model and prompts', function () {
        $fake = Prism::fake([TextResponseFake::make()->withText('Add a composite index.')]);

        $driver = new PrismDriver(Provider::Anthropic, 'claude-haiku-4-5', 'You are a DB expert.');
        $result = $driver->analyze('select * from users');

        expect($result)->toBe('Add a composite index.');

        $fake->assertCallCount(1);
        $fake->assertPrompt('select * from users');
        $fake->assertRequest(function (array $requests) {
            expect($requests[0]->provider())->toBe('anthropic')
                ->and($requests[0]->model())->toBe('claude-haiku-4-5');
        });
    });

    it('returns null when the model produces no text', function () {
        Prism::fake([TextResponseFake::make()->withText('')]);

        $driver = new PrismDriver(Provider::OpenAI, 'gpt-5.4-mini', 'system');

        expect($driver->analyze('select 1'))->toBeNull();
    });
});
