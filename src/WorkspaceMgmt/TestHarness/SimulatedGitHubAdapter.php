<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\TestHarness;

use App\WorkspaceMgmt\Infrastructure\Adapter\GitHubAdapterInterface;

/**
 * E2E/test double: no GitHub API calls; findPullRequestForBranch returns null, createPullRequest returns a constant URL.
 */
final class SimulatedGitHubAdapter implements GitHubAdapterInterface
{
    private const string FAKE_PR_URL = 'https://github.com/e2e-simulated/repo/pull/1';

    public function findPullRequestForBranch(
        string $owner,
        string $repo,
        string $branchName,
        string $token
    ): ?string {
        return null;
    }

    public function createPullRequest(
        string $owner,
        string $repo,
        string $branchName,
        string $title,
        string $body,
        string $token
    ): string {
        return self::FAKE_PR_URL;
    }
}
