<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Domain\Enum;

enum LlmModelName: string
{
    case Gpt52 = 'gpt-5.2';

    public function maxContextTokens(): int
    {
        return match ($this) {
            self::Gpt52 => 128_000,
        };
    }

    /**
     * Returns the cost per 1 million input tokens in USD.
     */
    public function inputCostPer1M(): float
    {
        return match ($this) {
            self::Gpt52 => 1.75,
        };
    }

    /**
     * Returns the cost per 1 million output tokens in USD.
     */
    public function outputCostPer1M(): float
    {
        return match ($this) {
            self::Gpt52 => 14.00,
        };
    }

    public static function defaultForContentEditor(): self
    {
        return self::Gpt52;
    }
}
