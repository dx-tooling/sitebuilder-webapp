<?php

declare(strict_types=1);

namespace App\AgenticContentEditor\Facade\Dto;

/**
 * Canonical agent event kinds: inference_start, inference_stop, tool_calling, tool_called, tool_error, agent_error.
 * Backends (e.g. Cursor) map their own events (e.g. build steps) to these via toolName (e.g. 'run_build').
 *
 * @param 'inference_start'|'inference_stop'|'tool_calling'|'tool_called'|'tool_error'|'agent_error' $kind
 * @param list<ToolInputEntryDto>|null                                                               $toolInputs
 * @param int|null                                                                                   $inputBytes  Actual byte length of tool inputs (for context-usage tracking)
 * @param int|null                                                                                   $resultBytes Actual byte length of tool result (for context-usage tracking)
 */
readonly class AgentEventDto
{
    /**
     * @param list<ToolInputEntryDto>|null $toolInputs
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
