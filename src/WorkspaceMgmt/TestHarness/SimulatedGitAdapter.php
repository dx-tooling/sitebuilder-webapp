<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\TestHarness;

use App\WorkspaceMgmt\Infrastructure\Adapter\GitAdapterInterface;
use App\WorkspaceMgmt\Infrastructure\Adapter\GitCliAdapter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

use function dirname;
use function is_dir;
use function mkdir;

use const DIRECTORY_SEPARATOR;

/**
 * E2E/test double: clone copies a local fixture and runs git init; push is a no-op.
 * Other git operations delegate to the real CLI adapter.
 */
final class SimulatedGitAdapter implements GitAdapterInterface
{
    public function __construct(
        private readonly string        $workspaceFixturePath,
        private readonly GitCliAdapter $realGitAdapter,
    ) {
    }

    public function clone(string $repoUrl, string $targetPath, string $token): void
    {
        $this->copyDirectory($this->workspaceFixturePath, $targetPath);

        $this->runProcess(new Process(['git', 'init']), $targetPath, 'Failed to init repo');
        $this->runProcess(new Process(['git', 'add', '-A']), $targetPath, 'Failed to stage');
        $this->runProcess(
            new Process(['git', '-c', 'user.name=E2E', '-c', 'user.email=e2e@example.com', 'commit', '-m', 'Initial']),
            $targetPath,
            'Failed to create initial commit'
        );
        $this->runProcess(new Process(['git', 'remote', 'add', 'origin', $repoUrl]), $targetPath, 'Failed to add remote');
    }

    public function checkoutNewBranch(string $workspacePath, string $branchName): void
    {
        $this->realGitAdapter->checkoutNewBranch($workspacePath, $branchName);
    }

    public function hasChanges(string $workspacePath): bool
    {
        return $this->realGitAdapter->hasChanges($workspacePath);
    }

    public function commitAll(
        string $workspacePath,
        string $message,
        string $authorName,
        string $authorEmail
    ): void {
        $this->realGitAdapter->commitAll($workspacePath, $message, $authorName, $authorEmail);
    }

    public function push(string $workspacePath, string $branchName, string $token): void
    {
        // No-op: no real push in e2e simulation
    }

    public function hasBranchDifferences(string $workspacePath, string $branchName, string $baseBranch = 'main'): bool
    {
        return $this->realGitAdapter->hasBranchDifferences($workspacePath, $branchName, $baseBranch);
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($source)) {
            throw new RuntimeException('Workspace fixture path is not a directory: ' . $source);
        }

        if (!is_dir($target)) {
            mkdir($target, 0777, true);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            $subPath = $iterator->getSubPathname();
            $dest    = $target . DIRECTORY_SEPARATOR . $subPath;

            if ($item->isDir()) {
                if (!is_dir($dest)) {
                    mkdir($dest, 0777, true);
                }
            } else {
                $dir = dirname($dest);
                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
                $realPath = $item->getRealPath();
                if ($realPath !== false) {
                    copy($realPath, $dest);
                }
            }
        }
    }

    private function runProcess(Process $process, string $workingDir, string $errorMessage = 'Command failed'): void
    {
        $process->setWorkingDirectory($workingDir);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }
}
