<?php

declare(strict_types=1);

namespace App\WorkspaceManagement\Facade\Dto;

use DateTimeImmutable;

readonly class WorkspaceDto
{
    public function __construct(
        public string            $id,
        public string            $projectId,
        public string            $containerId,
        public string            $status,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
