<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Infrastructure\Adapter;

use App\LlmContentEditor\Facade\Enum\LlmModelProvider;

/**
 * Generates a descriptive filename for an image based on its generation prompt.
 */
interface FileNameGeneratorInterface
{
    /**
     * Generate a descriptive, kebab-case .png filename from an image generation prompt.
     */
    public function generateFileName(
        string           $prompt,
        string           $apiKey,
        LlmModelProvider $provider,
    ): string;
}
