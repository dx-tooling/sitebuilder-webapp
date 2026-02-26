<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Facade\Dto;

/**
 * DTO representing git context information for a workspace.
 */
final readonly class WorkspaceGitInfoDto
{
    /**
     * @param list<WorkspaceCommitDto> $recentCommits
     * @param list<string>             $localBranches
     */
    public function __construct(
        public string $currentBranch,
        public array  $recentCommits,
        public array  $localBranches,
    ) {
    }
}
