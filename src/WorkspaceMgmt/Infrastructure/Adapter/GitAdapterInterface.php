<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Infrastructure\Adapter;

/**
 * Interface for git operations.
 * Implementations handle the actual git CLI commands.
 */
interface GitAdapterInterface
{
    /**
     * Clone a repository to a target path.
     *
     * @param string $repoUrl    the repository URL (e.g., https://github.com/org/repo.git)
     * @param string $targetPath the local path to clone into
     * @param string $token      the authentication token for private repos
     */
    public function clone(string $repoUrl, string $targetPath, string $token): void;

    /**
     * Create and checkout a new branch.
     *
     * @param string $workspacePath the workspace directory
     * @param string $branchName    the name of the new branch
     */
    public function checkoutNewBranch(string $workspacePath, string $branchName): void;

    /**
     * Check if there are uncommitted changes in the workspace.
     *
     * @param string $workspacePath the workspace directory
     *
     * @return bool true if there are changes, false otherwise
     */
    public function hasChanges(string $workspacePath): bool;

    /**
     * Stage all changes and commit with a message.
     *
     * @param string $workspacePath the workspace directory
     * @param string $message       the commit message
     * @param string $authorName    the author name for the commit
     * @param string $authorEmail   the author email for the commit
     */
    public function commitAll(
        string $workspacePath,
        string $message,
        string $authorName,
        string $authorEmail
    ): void;

    /**
     * Push the branch to remote.
     *
     * @param string $workspacePath the workspace directory
     * @param string $branchName    the branch to push
     * @param string $token         the authentication token
     */
    public function push(string $workspacePath, string $branchName, string $token): void;

    /**
     * Check if the branch has commits that differ from the base branch.
     *
     * @param string $workspacePath the workspace directory
     * @param string $branchName    the branch to check
     * @param string $baseBranch    the base branch to compare against (default: 'main')
     *
     * @return bool true if the branch has commits that differ from the base branch, false otherwise
     */
    public function hasBranchDifferences(string $workspacePath, string $branchName, string $baseBranch = 'main'): bool;
}
