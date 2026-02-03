<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Infrastructure\Service;

use App\WorkspaceMgmt\Infrastructure\Service\Dto\GitHubRepositoryInfoDto;
use RuntimeException;

use function preg_match;

/**
 * Service for generating GitHub URLs from git URLs and branch names.
 */
final readonly class GitHubUrlService implements GitHubUrlServiceInterface
{
    /**
     * Convert a git URL to a GitHub repository URL.
     * Handles: https://github.com/owner/repo.git -> https://github.com/owner/repo.
     */
    public function getRepositoryUrl(string $gitUrl): string
    {
        // Handle: https://github.com/owner/repo.git or https://github.com/owner/repo
        // Note: repo name can contain dots (e.g., dx-tooling.org)
        if (preg_match('#github\.com[/:]([^/]+)/(.+?)(?:\.git)?$#', $gitUrl, $matches)) {
            return 'https://github.com/' . $matches[1] . '/' . $matches[2];
        }

        throw new RuntimeException('Unable to parse git URL: ' . $gitUrl);
    }

    /**
     * Generate a GitHub branch URL.
     */
    public function getBranchUrl(string $gitUrl, string $branchName): string
    {
        $repoUrl = $this->getRepositoryUrl($gitUrl);

        return $repoUrl . '/tree/' . $branchName;
    }

    /**
     * Generate a GitHub pull request URL (if we know the PR number).
     * For finding PRs, use the GitHub API adapter instead.
     */
    public function getPullRequestUrl(string $gitUrl, int $prNumber): string
    {
        $repoUrl = $this->getRepositoryUrl($gitUrl);

        return $repoUrl . '/pull/' . $prNumber;
    }

    /**
     * Parse a git URL to extract owner and repo.
     */
    public function parseGitUrl(string $gitUrl): GitHubRepositoryInfoDto
    {
        // Handle: https://github.com/owner/repo.git
        // Note: repo name can contain dots (e.g., dx-tooling.org)
        if (preg_match('#github\.com[/:]([^/]+)/(.+?)(?:\.git)?$#', $gitUrl, $matches)) {
            return new GitHubRepositoryInfoDto($matches[1], $matches[2]);
        }

        throw new RuntimeException('Unable to parse git URL: ' . $gitUrl);
    }
}
