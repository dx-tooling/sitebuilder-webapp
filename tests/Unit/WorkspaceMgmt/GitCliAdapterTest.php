<?php

declare(strict_types=1);

namespace App\Tests\Unit\WorkspaceMgmt;

use App\WorkspaceMgmt\Infrastructure\Adapter\GitCliAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

use function count;
use function is_dir;
use function sys_get_temp_dir;
use function uniqid;

final class GitCliAdapterTest extends TestCase
{
    private string $testRepoPath;
    private GitCliAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adapter = new GitCliAdapter();

        // Create a temporary git repository for testing
        $this->testRepoPath = sys_get_temp_dir() . '/git-test-' . uniqid();
        mkdir($this->testRepoPath, 0777, true);

        $this->runGitCommand('init');
        $this->runGitCommand('config user.name "Test User"');
        $this->runGitCommand('config user.email "test@example.com"');

        // Create some initial commits
        file_put_contents($this->testRepoPath . '/file1.txt', 'Content 1');
        $this->runGitCommand('add .');
        $this->runGitCommand('commit -m "First commit"');

        file_put_contents($this->testRepoPath . '/file2.txt', 'Content 2');
        $this->runGitCommand('add .');
        $this->runGitCommand('commit -m "Second commit" -m "This is the body of the second commit"');

        file_put_contents($this->testRepoPath . '/file3.txt', 'Content 3');
        $this->runGitCommand('add .');
        $this->runGitCommand('commit -m "Third commit" -m "Body line 1" -m "Body line 2"');

        // Create a feature branch
        $this->runGitCommand('checkout -b feature-branch');
        file_put_contents($this->testRepoPath . '/file4.txt', 'Content 4');
        $this->runGitCommand('add .');
        $this->runGitCommand('commit -m "Feature commit"');

        // Go back to main
        $this->runGitCommand('checkout main');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up test repository
        if (is_dir($this->testRepoPath)) {
            $this->removeDirectory($this->testRepoPath);
        }
    }

    public function testGetCurrentBranch(): void
    {
        $currentBranch = $this->adapter->getCurrentBranch($this->testRepoPath);

        self::assertSame('main', $currentBranch);
    }

    public function testGetCurrentBranchOnFeatureBranch(): void
    {
        $this->runGitCommand('checkout feature-branch');

        $currentBranch = $this->adapter->getCurrentBranch($this->testRepoPath);

        self::assertSame('feature-branch', $currentBranch);
    }

    public function testGetRecentCommits(): void
    {
        $commits = $this->adapter->getRecentCommits($this->testRepoPath, 10);

        self::assertCount(3, $commits);

        // Check first commit (most recent)
        self::assertArrayHasKey('hash', $commits[0]);
        self::assertArrayHasKey('subject', $commits[0]);
        self::assertArrayHasKey('body', $commits[0]);
        self::assertArrayHasKey('timestamp', $commits[0]);

        self::assertSame('Third commit', $commits[0]['subject']);
        self::assertStringContainsString('Body line 1', $commits[0]['body']);
        self::assertStringContainsString('Body line 2', $commits[0]['body']);

        // Check second commit
        self::assertSame('Second commit', $commits[1]['subject']);
        self::assertStringContainsString('This is the body of the second commit', $commits[1]['body']);

        // Check third commit (oldest)
        self::assertSame('First commit', $commits[2]['subject']);
        self::assertSame('', $commits[2]['body']);
    }

    public function testGetRecentCommitsWithLimit(): void
    {
        $commits = $this->adapter->getRecentCommits($this->testRepoPath, 2);

        self::assertCount(2, $commits);
        self::assertSame('Third commit', $commits[0]['subject']);
        self::assertSame('Second commit', $commits[1]['subject']);
    }

    public function testGetRecentCommitsReturnsEmptyArrayForNewRepo(): void
    {
        $emptyRepoPath = sys_get_temp_dir() . '/git-empty-' . uniqid();
        mkdir($emptyRepoPath, 0777, true);

        $process = new Process(['git', 'init']);
        $process->setWorkingDirectory($emptyRepoPath);
        $process->run();

        $commits = $this->adapter->getRecentCommits($emptyRepoPath, 10);

        self::assertSame([], $commits);

        $this->removeDirectory($emptyRepoPath);
    }

    public function testGetBranches(): void
    {
        $branches = $this->adapter->getBranches($this->testRepoPath);

        self::assertCount(2, $branches);
        self::assertContains('main', $branches);
        self::assertContains('feature-branch', $branches);
    }

    public function testGetBranchesReturnsEmptyForNewRepo(): void
    {
        $emptyRepoPath = sys_get_temp_dir() . '/git-empty-' . uniqid();
        mkdir($emptyRepoPath, 0777, true);

        $process = new Process(['git', 'init']);
        $process->setWorkingDirectory($emptyRepoPath);
        $process->run();

        $branches = $this->adapter->getBranches($emptyRepoPath);

        self::assertSame([], $branches);

        $this->removeDirectory($emptyRepoPath);
    }

    public function testGetRecentCommitsHandlesMultilineBody(): void
    {
        file_put_contents($this->testRepoPath . '/file5.txt', 'Content 5');
        $this->runGitCommand('add .');
        $this->runGitCommand('commit -m "Multiline commit" -m "Line 1" -m "" -m "Line 3"');

        $commits = $this->adapter->getRecentCommits($this->testRepoPath, 1);

        self::assertCount(1, $commits);
        self::assertSame('Multiline commit', $commits[0]['subject']);

        $body = $commits[0]['body'];
        self::assertStringContainsString('Line 1', $body);
        self::assertStringContainsString('Line 3', $body);
    }

    public function testGetRecentCommitsTimestampIsIso8601(): void
    {
        $commits = $this->adapter->getRecentCommits($this->testRepoPath, 1);

        self::assertCount(1, $commits);

        // Verify timestamp is in ISO 8601 format (e.g., 2024-01-01T12:00:00+00:00)
        $timestamp = $commits[0]['timestamp'];
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $timestamp);
    }

    private function runGitCommand(string $command): void
    {
        $process = Process::fromShellCommandline('git ' . $command);
        $process->setWorkingDirectory($this->testRepoPath);
        $process->setTimeout(10);
        $process->mustRun();
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . '/' . $item;
            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }

        rmdir($path);
    }
}
