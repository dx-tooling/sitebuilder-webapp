<?php

declare(strict_types=1);

namespace App\LlmIntegration\Facade\Dto;

readonly class ToolCallDto
{
    /**
     * @param string $argumentsJson JSON-encoded arguments for the tool call
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $argumentsJson,
    ) {
    }
}
