<?php

declare(strict_types=1);

namespace App\LlmIntegration\Facade\Dto;

readonly class LlmResponseDto
{
    /**
     * @param list<ToolCallDto> $toolCalls
     */
    public function __construct(
        public ?string     $content,
        public array       $toolCalls,
        public string      $finishReason,
        public LlmUsageDto $usage,
    ) {
    }
}
