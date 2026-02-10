<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Facade\Dto;

readonly class AgentEventDto
{
    /**
     * @param 'inference_start'|'inference_stop'|'tool_calling'|'tool_called'|'agent_error'|'build_start'|'build_complete'|'build_error' $kind
     * @param list<ToolInputEntryDto>|null                                                                                               $toolInputs
     * @param int|null                                                                                                                   $inputBytes  Actual byte length of tool inputs (for context-usage tracking)
     * @param int|null                                                                                                                   $resultBytes Actual byte length of tool result (for context-usage tracking)
     */
    public function __construct(
        public string  $kind,
        public ?string $toolName = null,
        public ?array  $toolInputs = null,
        public ?string $toolResult = null,
        public ?string $errorMessage = null,
        public ?int    $inputBytes = null,
        public ?int    $resultBytes = null,
    ) {
    }
}
