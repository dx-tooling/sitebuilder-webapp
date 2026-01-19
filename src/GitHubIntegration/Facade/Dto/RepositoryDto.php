<?php

declare(strict_types=1);

namespace App\GitHubIntegration\Facade\Dto;

use DateTimeImmutable;

readonly class RepositoryDto
{
    public function __construct(
        public string            $id,
        public string            $name,
        public string            $fullName,
        public string            $cloneUrl,
        public string            $htmlUrl,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
