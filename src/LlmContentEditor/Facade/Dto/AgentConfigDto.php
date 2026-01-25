<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Facade\Dto;

/**
 * DTO for passing agent configuration to the LLM content editor.
 * Contains the three instruction sets that define agent behavior.
 */
final readonly class AgentConfigDto
{
    public function __construct(
        public string $backgroundInstructions,
        public string $stepInstructions,
        public string $outputInstructions,
    ) {
    }
}
