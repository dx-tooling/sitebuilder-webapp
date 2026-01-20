<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\Provider\Dto;

/**
 * DTO representing a single tool input parameter entry.
 */
readonly class ToolInputEntryDto
{
    public function __construct(
        public string $name,
        public mixed  $value
    ) {
    }
}
