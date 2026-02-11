<?php

declare(strict_types=1);

namespace App\AgenticContentEditor\Facade\Dto;

/**
 * Backend-neutral model information for context usage and cost estimation.
 *
 * Each adapter reports its own values:
 * - LLM backends provide real model names, context limits, and per-token costs.
 * - Agent backends (e.g. Cursor) may report null costs when pricing is opaque.
 */
final readonly class BackendModelInfoDto
{
    public function __construct(
        /** Human-readable model identifier (e.g. "gpt-5.2", "cursor-agent"). */
        public string $modelName,
        /** Maximum context window size in tokens. */
        public int    $maxContextTokens,
        /** Cost per 1M input tokens in USD, or null if not applicable. */
        public ?float $inputCostPer1M = null,
        /** Cost per 1M output tokens in USD, or null if not applicable. */
        public ?float $outputCostPer1M = null,
    ) {
    }
}
