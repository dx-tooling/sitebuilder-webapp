<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Facade\Dto;

readonly class AgentEventDto
{
    /**
     * @param 'inference_start'|'inference_stop'|'tool_calling'|'tool_called'|'agent_error' $kind
     * @param list<ToolInputEntryDto>|null                                                  $toolInputs
     */
    public function __construct(
        public string  $kind,
        public ?string $toolName = null,
        public ?array  $toolInputs = null,
        public ?string $toolResult = null,
        public ?string $errorMessage = null,
    ) {
    }
}
