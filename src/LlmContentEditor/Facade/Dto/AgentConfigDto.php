<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Facade\Dto;

/**
 * DTO for passing agent configuration to the LLM content editor.
 * Contains the three instruction sets that define agent behavior.
 *
 * When workingFolderPath is set (e.g. "/workspace"), it is appended to the system
 * prompt so the agent always has the path even after context-window trimming.
 *
 * @see https://github.com/dx-tooling/sitebuilder-webapp/issues/79
 */
final readonly class AgentConfigDto
{
    public function __construct(
        public string  $backgroundInstructions,
        public string  $stepInstructions,
        public string  $outputInstructions,
        public ?string $workingFolderPath = null,
    ) {
    }
}
