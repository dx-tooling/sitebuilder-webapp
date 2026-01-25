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

    /**
     * Returns the models supported by this provider.
     *
     * @return list<LlmModelName>
     */
    public function supportedModels(): array
    {
        return match ($this) {
            self::OpenAI => [LlmModelName::Gpt52],
        };
    }

    /**
     * Returns the default model for this provider (used for API key verification).
     */
    public function defaultModel(): LlmModelName
    {
        return $this->supportedModels()[0];
    }

    /**
     * Returns a human-readable display name for the provider.
     */
    public function displayName(): string
    {
        return match ($this) {
            self::OpenAI => 'OpenAI',
        };
    }
}
