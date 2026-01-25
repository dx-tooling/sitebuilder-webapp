<?php

declare(strict_types=1);

namespace App\ProjectMgmt\Facade\Dto;

/**
 * DTO for agent configuration template.
 * Used to expose default templates based on project type.
 */
final readonly class AgentConfigTemplateDto
{
    public function __construct(
        public string $backgroundInstructions,
        public string $stepInstructions,
        public string $outputInstructions,
    ) {
    }
}
