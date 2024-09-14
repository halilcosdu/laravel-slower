<?php

namespace HalilCosdu\Slower\AiServiceDrivers\Contracts;

interface AiServiceDriver
{
    public function analyze(string $userMessage): ?string;
}
