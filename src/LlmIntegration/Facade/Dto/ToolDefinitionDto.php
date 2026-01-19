<?php

declare(strict_types=1);

namespace App\LlmIntegration\Facade\Dto;

readonly class ToolDefinitionDto
{
    /**
     * @param string $parametersJson JSON-encoded parameter schema for the tool
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $parametersJson,
    ) {
    }
}
