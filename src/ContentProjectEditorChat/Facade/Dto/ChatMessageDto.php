<?php

declare(strict_types=1);

namespace App\ContentProjectEditorChat\Facade\Dto;

use DateTimeImmutable;

readonly class ChatMessageDto
{
    public function __construct(
        public string            $id,
        public string            $sessionId,
        public string            $role,
        public string            $content,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
