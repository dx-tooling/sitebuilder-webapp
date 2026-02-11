<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Facade\Enum;

use App\LlmContentEditor\Domain\Enum\LlmModelName;

/**
 * LLM model providers supported by the application.
 * Exposed via Facade for cross-vertical use (e.g., ProjectMgmt).
 */
enum LlmModelProvider: string
{
    case OpenAI = 'openai';
    case Google = 'google';

    /**
     * Returns the text-generation models supported by this provider.
     *
     * @return list<LlmModelName>
     */
    public function supportedTextModels(): array
    {
        return match ($this) {
            self::OpenAI => [LlmModelName::Gpt52],
            self::Google => [LlmModelName::Gemini3ProPreview],
        };
    }

    /**
     * Returns the image-generation model for this provider.
     */
    public function imageGenerationModel(): LlmModelName
    {
        return match ($this) {
            self::OpenAI => LlmModelName::GptImage1,
            self::Google => LlmModelName::Gemini3ProImagePreview,
        };
    }

    /**
     * Returns the text-generation model to use for image prompt generation.
     */
    public function imagePromptGenerationModel(): LlmModelName
    {
        return match ($this) {
            self::OpenAI => LlmModelName::Gpt52,
            self::Google => LlmModelName::Gemini3ProPreview,
        };
    }

    /**
     * Returns the default model for this provider (used for API key verification).
     */
    public function defaultModel(): LlmModelName
    {
        return $this->supportedTextModels()[0];
    }

    /**
     * Returns a human-readable display name for the provider.
     */
    public function displayName(): string
    {
        return match ($this) {
            self::OpenAI => 'OpenAI',
            self::Google => 'Google (Gemini)',
        };
    }

    /**
     * Returns true if this provider is available for content editing.
     */
    public function supportsContentEditing(): bool
    {
        return match ($this) {
            self::OpenAI => true,
            self::Google => false,
        };
    }

    /**
     * Returns true if this provider is available for PhotoBuilder (image generation).
     */
    public function supportsPhotoBuilder(): bool
    {
        return true;
    }
}
