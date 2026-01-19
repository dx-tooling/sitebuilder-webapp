<?php

declare(strict_types=1);

namespace App\GitHubIntegration\Facade;

use App\GitHubIntegration\Facade\Dto\CommitDto;
use App\GitHubIntegration\Facade\Dto\RepositoryDto;

interface GitHubIntegrationFacadeInterface
{
    public function createRepositoryFromTemplate(string $token, string $orgName, string $repoName): RepositoryDto;

    public function cloneRepository(string $token, string $repoUrl, string $targetPath): void;

    public function commitAndPush(string $token, string $repoPath, string $message): CommitDto;

    /**
     * @return list<CommitDto>
     */
    public function getCommitHistory(string $token, string $repoUrl): array;

    public function checkoutCommit(string $repoPath, string $commitSha): void;

    public function validateToken(string $token): bool;
}
