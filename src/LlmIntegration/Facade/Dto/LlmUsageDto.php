<?php

declare(strict_types=1);

namespace App\LlmIntegration\Facade\Dto;

readonly class LlmUsageDto
{
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
        public int $totalTokens,
    ) {
    }
}
