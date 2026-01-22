<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Infrastructure\Service\Dto;

/**
 * DTO for GitHub repository information parsed from a git URL.
 */
final readonly class GitHubRepositoryInfoDto
{
    public function __construct(
        public string $owner,
        public string $repo,
    ) {
    }
}
