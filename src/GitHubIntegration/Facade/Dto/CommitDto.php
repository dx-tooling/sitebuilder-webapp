<?php

declare(strict_types=1);

namespace App\GitHubIntegration\Facade\Dto;

use DateTimeImmutable;

readonly class CommitDto
{
    public function __construct(
        public string            $sha,
        public string            $message,
        public string            $authorName,
        public string            $authorEmail,
        public DateTimeImmutable $committedAt,
    ) {
    }
}
