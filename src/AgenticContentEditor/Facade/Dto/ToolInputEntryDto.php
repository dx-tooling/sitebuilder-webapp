<?php

declare(strict_types=1);

namespace App\AgenticContentEditor\Facade\Dto;

readonly class ToolInputEntryDto
{
    public function __construct(
        public string $key,
        public string $value,
    ) {
    }
}
