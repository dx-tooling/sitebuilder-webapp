<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Domain\Enum;

enum LlmModelName: string
{
    // Text generation models
    case Gpt52             = 'gpt-5.2';
    case Gemini3ProPreview = 'gemini-3-pro-preview';

    // Image generation models
    case GptImage1              = 'gpt-image-1';
    case Gemini3ProImagePreview = 'gemini-3-pro-image-preview';

    public function maxContextTokens(): int
    {
        return match ($this) {
            self::Gpt52             => 128_000,
            self::Gemini3ProPreview => 1_048_576,
            self::GptImage1, self::Gemini3ProImagePreview => 0,
        };
    }

    /**
     * Returns the cost per 1 million input tokens in USD.
     */
    public function inputCostPer1M(): float
    {
        return match ($this) {
            self::Gpt52                  => 1.75,
            self::Gemini3ProPreview      => 1.25,
            self::GptImage1              => 0.0, // image models have per-image pricing
            self::Gemini3ProImagePreview => 0.0,
        };
    }

    /**
     * Returns the cost per 1 million output tokens in USD.
     */
    public function outputCostPer1M(): float
    {
        return match ($this) {
            self::Gpt52                  => 14.00,
            self::Gemini3ProPreview      => 10.00,
            self::GptImage1              => 0.0,
            self::Gemini3ProImagePreview => 0.0,
        };
    }

    public static function defaultForContentEditor(): self
    {
        return self::Gpt52;
    }

    /**
     * Returns true if this model is used for image generation (not text).
     */
    public function isImageGenerationModel(): bool
    {
        return match ($this) {
            self::GptImage1, self::Gemini3ProImagePreview => true,
            default => false,
        };
    }
}
