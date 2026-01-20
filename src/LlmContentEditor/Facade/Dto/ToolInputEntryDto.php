<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Facade\Dto;

readonly class ToolInputEntryDto
{
    public function __construct(
        public string $key,
        public string $value,
    ) {
    }
}
