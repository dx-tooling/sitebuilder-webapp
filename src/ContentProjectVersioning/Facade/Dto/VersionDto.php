<?php

declare(strict_types=1);

namespace App\ContentProjectVersioning\Facade\Dto;

use DateTimeImmutable;

readonly class VersionDto
{
    public function __construct(
        public string            $id,
        public string            $projectId,
        public string            $commitSha,
        public string            $message,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
