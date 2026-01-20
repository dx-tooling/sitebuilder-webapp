<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\Provider\Dto;

/**
 * DTO representing a tool call rule for the fake provider.
 */
readonly class ToolCallRuleDto
{
    public function __construct(
        public string        $pattern,
        public string        $toolName,
        public ToolInputsDto $toolInputs
    ) {
    }
}
