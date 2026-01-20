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

    public static function defaultForContentEditor(): self
    {
        return self::Gpt52;
    }
}
