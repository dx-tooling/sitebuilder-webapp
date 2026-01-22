<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Presentation\Dto;

readonly class ContextUsageDto
{
    public function __construct(
        public int    $usedTokens,
        public int    $maxTokens,
        public string $modelName,
        public int    $inputTokens,
        public int    $outputTokens,
        public float  $inputCost,
        public float  $outputCost,
        public float  $totalCost,
    ) {
    }
}
