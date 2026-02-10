<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Infrastructure\Adapter;

/**
 * Generates images from text prompts using an AI image generation API.
 */
interface ImageGeneratorInterface
{
    /**
     * Generate an image from a text prompt.
     *
     * @return string Raw image data (PNG bytes)
     */
    public function generateImage(string $prompt, string $apiKey): string;
}
