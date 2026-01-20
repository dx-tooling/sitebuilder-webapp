<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\Provider;

use NeuronAI\Providers\AIProviderInterface;

interface AIProviderFactoryInterface
{
    public function createProvider(): AIProviderInterface;
}
