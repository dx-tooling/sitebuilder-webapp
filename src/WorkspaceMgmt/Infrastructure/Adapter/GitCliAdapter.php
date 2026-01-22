<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Infrastructure\Adapter;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Git adapter implementation using CLI commands via Symfony Process.
 */
final class GitCliAdapter implements GitAdapterInterface
{
    private const int TIMEOUT_SECONDS = 300;

    public function clone(string $repoUrl, string $targetPath, string $token): void
    {
        // Use inline credential helper to provide authentication
        // This avoids URL parsing issues with embedded credentials
        $credentialHelper = $this->buildCredentialHelper($token);

        $process = new Process([
            'git',
            '-c', 'credential.helper=' . $credentialHelper,
            'clone',
            $repoUrl,
            $targetPath,
        ]);
        $process->setTimeout(self::TIMEOUT_SECONDS);

        $this->runProcess($process, 'Failed to clone repository');
    }

    public function checkoutNewBranch(string $workspacePath, string $branchName): void
    {
        $process = new Process(['git', 'checkout', '-b', $branchName]);
        $process->setWorkingDirectory($workspacePath);
        $process->setTimeout(self::TIMEOUT_SECONDS);

        $this->runProcess($process, 'Failed to create and checkout branch');
    }

    public function hasChanges(string $workspacePath): bool
    {
        $process = new Process(['git', 'status', '--porcelain']);
        $process->setWorkingDirectory($workspacePath);
        $process->setTimeout(self::TIMEOUT_SECONDS);

        $this->runProcess($process, 'Failed to check git status');

        return trim($process->getOutput()) !== '';
    }

    public function commitAll(
        string $workspacePath,
        string $message,
        string $authorName,
        string $authorEmail
    ): void {
        // Stage all changes
        $addProcess = new Process(['git', 'add', '-A']);
        $addProcess->setWorkingDirectory($workspacePath);
        $addProcess->setTimeout(self::TIMEOUT_SECONDS);
        $this->runProcess($addProcess, 'Failed to stage changes');

        // Commit with author info using -c flags (no global config change needed)
        $commitProcess = new Process([
            'git',
            '-c', 'user.name=' . $authorName,
            '-c', 'user.email=' . $authorEmail,
            'commit',
            '-m', $message,
        ]);
        $commitProcess->setWorkingDirectory($workspacePath);
        $commitProcess->setTimeout(self::TIMEOUT_SECONDS);
        $this->runProcess($commitProcess, 'Failed to commit changes');
    }

    public function push(string $workspacePath, string $branchName, string $token): void
    {
        // Ensure remote URL is clean (no embedded credentials)
        $this->ensureCleanRemoteUrl($workspacePath);

        // Use inline credential helper to provide authentication
        $credentialHelper = $this->buildCredentialHelper($token);

        $pushProcess = new Process([
            'git',
            '-c', 'credential.helper=' . $credentialHelper,
            'push',
            '-u',
            'origin',
            $branchName,
        ]);
        $pushProcess->setWorkingDirectory($workspacePath);
        $pushProcess->setTimeout(self::TIMEOUT_SECONDS);
        $this->runProcess($pushProcess, 'Failed to push branch');
    }

    public function hasBranchDifferences(string $workspacePath, string $branchName, string $baseBranch = 'main'): bool
    {
        // Fetch latest refs to ensure we're comparing with remote state
        $fetchProcess = new Process(['git', 'fetch', 'origin', $baseBranch . ':' . $baseBranch, '--quiet']);
        $fetchProcess->setWorkingDirectory($workspacePath);
        $fetchProcess->setTimeout(self::TIMEOUT_SECONDS);
        // Don't fail if fetch fails - branch might not exist remotely yet
        $fetchProcess->run();

        // Check if branch exists locally
        $branchExistsProcess = new Process(['git', 'rev-parse', '--verify', $branchName]);
        $branchExistsProcess->setWorkingDirectory($workspacePath);
        $branchExistsProcess->setTimeout(self::TIMEOUT_SECONDS);
        $branchExistsProcess->run();

        if (!$branchExistsProcess->isSuccessful()) {
            // Branch doesn't exist, so no differences
            return false;
        }

        // Check if base branch exists remotely
        $baseExistsProcess = new Process(['git', 'rev-parse', '--verify', 'origin/' . $baseBranch]);
        $baseExistsProcess->setWorkingDirectory($workspacePath);
        $baseExistsProcess->setTimeout(self::TIMEOUT_SECONDS);
        $baseExistsProcess->run();

        if (!$baseExistsProcess->isSuccessful()) {
            // Base branch doesn't exist remotely, check locally
            $localBaseProcess = new Process(['git', 'rev-parse', '--verify', $baseBranch]);
            $localBaseProcess->setWorkingDirectory($workspacePath);
            $localBaseProcess->setTimeout(self::TIMEOUT_SECONDS);
            $localBaseProcess->run();

            if (!$localBaseProcess->isSuccessful()) {
                // Base branch doesn't exist at all, consider branch as having differences
                // (it's a new branch with commits)
                return true;
            }

            // Use rev-list to check if branch has commits not in base branch
            // This is more reliable than diff for checking if branches differ
            $revListProcess = new Process(['git', 'rev-list', '--count', $baseBranch . '..' . $branchName]);
            $revListProcess->setWorkingDirectory($workspacePath);
            $revListProcess->setTimeout(self::TIMEOUT_SECONDS);
            $revListProcess->run();

            if (!$revListProcess->isSuccessful()) {
                // If rev-list fails, fall back to diff check
                $diffProcess = new Process(['git', 'diff', '--quiet', $baseBranch . '..' . $branchName]);
                $diffProcess->setWorkingDirectory($workspacePath);
                $diffProcess->setTimeout(self::TIMEOUT_SECONDS);
                $diffProcess->run();

                return !$diffProcess->isSuccessful();
            }

            $commitCount = (int) trim($revListProcess->getOutput());

            return $commitCount > 0;
        }

        // Use rev-list to check if branch has commits not in remote base branch
        $revListProcess = new Process(['git', 'rev-list', '--count', 'origin/' . $baseBranch . '..' . $branchName]);
        $revListProcess->setWorkingDirectory($workspacePath);
        $revListProcess->setTimeout(self::TIMEOUT_SECONDS);
        $revListProcess->run();

        if (!$revListProcess->isSuccessful()) {
            // If rev-list fails, fall back to diff check
            $diffProcess = new Process(['git', 'diff', '--quiet', 'origin/' . $baseBranch . '..' . $branchName]);
            $diffProcess->setWorkingDirectory($workspacePath);
            $diffProcess->setTimeout(self::TIMEOUT_SECONDS);
            $diffProcess->run();

            return !$diffProcess->isSuccessful();
        }

        $commitCount = (int) trim($revListProcess->getOutput());

        return $commitCount > 0;
    }

    /**
     * Build an inline credential helper that provides the token.
     * Uses a shell function that outputs git credential protocol format.
     */
    private function buildCredentialHelper(string $token): string
    {
        // Escape single quotes in token (though GitHub tokens shouldn't contain them)
        $escapedToken = str_replace("'", "'\\''", $token);

        // Git credential helper that outputs username and password
        // The '!' prefix tells git to execute this as a shell command
        return "!f() { echo \"username=x-access-token\"; echo \"password={$escapedToken}\"; }; f";
    }

    /**
     * Ensure the remote URL doesn't contain embedded credentials.
     * This cleans up URLs that might have been set with tokens embedded.
     */
    private function ensureCleanRemoteUrl(string $workspacePath): void
    {
        $remoteUrlProcess = new Process(['git', 'remote', 'get-url', 'origin']);
        $remoteUrlProcess->setWorkingDirectory($workspacePath);
        $remoteUrlProcess->setTimeout(self::TIMEOUT_SECONDS);
        $this->runProcess($remoteUrlProcess, 'Failed to get remote URL');

        $remoteUrl = trim($remoteUrlProcess->getOutput());

        // If URL contains credentials (has @ before github.com), clean it
        if (preg_match('#^https://[^@]+@(.+)$#', $remoteUrl, $matches)) {
            $cleanUrl = 'https://' . $matches[1];

            $setUrlProcess = new Process(['git', 'remote', 'set-url', 'origin', $cleanUrl]);
            $setUrlProcess->setWorkingDirectory($workspacePath);
            $setUrlProcess->setTimeout(self::TIMEOUT_SECONDS);
            $this->runProcess($setUrlProcess, 'Failed to clean remote URL');
        }
    }

    private function runProcess(Process $process, string $errorMessage): void
    {
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }
}
