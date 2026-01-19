<?php

declare(strict_types=1);

namespace App\LlmIntegration\Facade\Dto;

readonly class LlmStreamDto
{
    public function __construct(
        public string $streamId,
        public string $status,
    ) {
    }
}
