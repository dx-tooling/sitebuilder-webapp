<?php

declare(strict_types=1);

namespace App\ContentProjectEditorChat\Facade\Dto;

readonly class StreamHandleDto
{
    public function __construct(
        public string $streamId,
        public string $sessionId,
        public string $status,
    ) {
    }
}
