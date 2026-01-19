<?php

declare(strict_types=1);

namespace App\ContentProjectEditor\Facade\Dto;

use DateTimeImmutable;

readonly class EditorSessionDto
{
    public function __construct(
        public string            $id,
        public string            $projectId,
        public string            $userId,
        public string            $workspaceId,
        public DateTimeImmutable $startedAt,
    ) {
    }
}
