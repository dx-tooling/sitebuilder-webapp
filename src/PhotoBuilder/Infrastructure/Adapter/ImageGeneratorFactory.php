<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Infrastructure\Adapter;

use App\LlmContentEditor\Facade\Enum\LlmModelProvider;

/**
 * Factory that returns the correct ImageGeneratorInterface implementation
 * based on the given LLM model provider.
 */
final class ImageGeneratorFactory
{
    public function __construct(
        private readonly OpenAiImageGenerator $openAiImageGenerator,
        private readonly GeminiImageGenerator $geminiImageGenerator,
    ) {
    }

    public function create(LlmModelProvider $provider): ImageGeneratorInterface
    {
        return match ($provider) {
            LlmModelProvider::OpenAI => $this->openAiImageGenerator,
            LlmModelProvider::Google => $this->geminiImageGenerator,
        };
    }
}
