<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Facade\Dto;

use DateTimeImmutable;

/**
 * DTO representing a git commit.
 */
final readonly class WorkspaceCommitDto
{
    public function __construct(
        public string            $hash,
        public string            $message,
        public string            $body,
        public DateTimeImmutable $committedAt,
    ) {
    }
}
