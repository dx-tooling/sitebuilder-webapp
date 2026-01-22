<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Infrastructure\Adapter;

use RuntimeException;
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
        // Inject token into URL for authentication
        $authenticatedUrl = $this->injectTokenIntoUrl($repoUrl, $token);

        $process = new Process(['git', 'clone', $authenticatedUrl, $targetPath]);
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
        // Get the remote URL and inject token
        $remoteUrlProcess = new Process(['git', 'remote', 'get-url', 'origin']);
        $remoteUrlProcess->setWorkingDirectory($workspacePath);
        $remoteUrlProcess->setTimeout(self::TIMEOUT_SECONDS);
        $this->runProcess($remoteUrlProcess, 'Failed to get remote URL');

        $remoteUrl        = trim($remoteUrlProcess->getOutput());
        $authenticatedUrl = $this->injectTokenIntoUrl($remoteUrl, $token);

        // Set the authenticated URL temporarily
        $setUrlProcess = new Process(['git', 'remote', 'set-url', 'origin', $authenticatedUrl]);
        $setUrlProcess->setWorkingDirectory($workspacePath);
        $setUrlProcess->setTimeout(self::TIMEOUT_SECONDS);
        $this->runProcess($setUrlProcess, 'Failed to set remote URL');

        try {
            // Push the branch
            $pushProcess = new Process(['git', 'push', '-u', 'origin', $branchName]);
            $pushProcess->setWorkingDirectory($workspacePath);
            $pushProcess->setTimeout(self::TIMEOUT_SECONDS);
            $this->runProcess($pushProcess, 'Failed to push branch');
        } finally {
            // Restore the original URL (without token) for security
            $restoreUrlProcess = new Process(['git', 'remote', 'set-url', 'origin', $remoteUrl]);
            $restoreUrlProcess->setWorkingDirectory($workspacePath);
            $restoreUrlProcess->setTimeout(self::TIMEOUT_SECONDS);
            $restoreUrlProcess->run();
        }
    }

    private function injectTokenIntoUrl(string $url, string $token): string
    {
        // Handle HTTPS URLs: https://github.com/... -> https://token@github.com/...
        if (str_starts_with($url, 'https://')) {
            return 'https://' . $token . '@' . mb_substr($url, 8);
        }

        // Handle URLs that might already have a token/user
        if (preg_match('#^https://[^@]+@#', $url)) {
            return (string) preg_replace('#^https://[^@]+@#', 'https://' . $token . '@', $url);
        }

        throw new RuntimeException('Unsupported git URL format: ' . $url);
    }

    private function runProcess(Process $process, string $errorMessage): void
    {
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }
}
