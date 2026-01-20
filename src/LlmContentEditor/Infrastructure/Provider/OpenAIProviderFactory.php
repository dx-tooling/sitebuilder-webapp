<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\Provider;

use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\OpenAI;

final class OpenAIProviderFactory implements AIProviderFactoryInterface
{
    public function createProvider(): AIProviderInterface
    {
        /** @var string $apiKey */
        $apiKey = $_ENV['LLM_CONTENT_EDITOR_OPENAI_API_KEY'] ?? '';

        return new OpenAI(
            $apiKey,
            'gpt-5.2',
        );
    }
}
