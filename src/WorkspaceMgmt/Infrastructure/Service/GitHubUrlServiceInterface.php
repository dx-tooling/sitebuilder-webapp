<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Infrastructure\Service;

use App\WorkspaceMgmt\Infrastructure\Service\Dto\GitHubRepositoryInfoDto;

/**
 * Interface for generating GitHub URLs.
 */
interface GitHubUrlServiceInterface
{
    /**
     * Convert a git URL to a GitHub repository URL.
     * Handles: https://github.com/owner/repo.git -> https://github.com/owner/repo.
     */
    public function getRepositoryUrl(string $gitUrl): string;

    /**
     * Generate a GitHub branch URL.
     */
    public function getBranchUrl(string $gitUrl, string $branchName): string;

    /**
     * Generate a GitHub pull request URL (if we know the PR number).
     * For finding PRs, use the GitHub API adapter instead.
     */
    public function getPullRequestUrl(string $gitUrl, int $prNumber): string;

    /**
     * Parse a git URL to extract owner and repo.
     */
    public function parseGitUrl(string $gitUrl): GitHubRepositoryInfoDto;
}
