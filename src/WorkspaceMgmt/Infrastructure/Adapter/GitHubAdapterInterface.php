<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Infrastructure\Adapter;

/**
 * Interface for GitHub API operations.
 */
interface GitHubAdapterInterface
{
    /**
     * Find an existing pull request for a branch.
     *
     * @param string $owner      the repository owner
     * @param string $repo       the repository name
     * @param string $branchName the branch to find PR for
     * @param string $token      the GitHub token
     *
     * @return string|null the PR URL if found, null otherwise
     */
    public function findPullRequestForBranch(
        string $owner,
        string $repo,
        string $branchName,
        string $token
    ): ?string;

    /**
     * Create a new pull request.
     *
     * @param string $owner      the repository owner
     * @param string $repo       the repository name
     * @param string $branchName the head branch
     * @param string $title      the PR title
     * @param string $body       the PR description
     * @param string $token      the GitHub token
     *
     * @return string the PR URL
     */
    public function createPullRequest(
        string $owner,
        string $repo,
        string $branchName,
        string $title,
        string $body,
        string $token
    ): string;
}
