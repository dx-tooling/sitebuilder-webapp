<?php

declare(strict_types=1);

namespace App\LlmIntegration\Facade\Dto;

readonly class ToolResultDto
{
    public function __construct(
        public string  $toolCallId,
        public bool    $success,
        public ?string $result,
        public ?string $errorMessage,
    ) {
    }
}
