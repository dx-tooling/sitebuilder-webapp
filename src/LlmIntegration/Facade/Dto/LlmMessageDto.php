<?php

declare(strict_types=1);

namespace App\LlmIntegration\Facade\Dto;

readonly class LlmMessageDto
{
    public function __construct(
        public string $role,
        public string $content,
    ) {
    }
}
