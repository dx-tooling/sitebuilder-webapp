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
     * @param string      $model     Model identifier (e.g. "gemini-3-pro-image-preview", "gpt-image-1")
     * @param string|null $imageSize Optional target resolution (e.g. "1K", "2K", "4K"). Only supported by Gemini.
     *
     * @return string Raw image data (PNG bytes)
     */
    public function generateImage(string $prompt, string $apiKey, string $model, ?string $imageSize = null): string;
}
