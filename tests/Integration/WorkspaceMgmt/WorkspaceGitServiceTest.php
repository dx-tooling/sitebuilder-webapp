<?php

declare(strict_types=1);

namespace App\Tests\Integration\WorkspaceMgmt;

use App\WorkspaceMgmt\Domain\Entity\Workspace;
use App\WorkspaceMgmt\Domain\Service\WorkspaceGitService;
use App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

use function assert;
use function is_dir;
use function is_string;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Integration test for WorkspaceGitService::getGitInfo.
 * Tests the full integration with GitCliAdapter using a real git repository.
 */
final class WorkspaceGitServiceTest extends KernelTestCase
{
    private string $testRepoPath;
    private WorkspaceGitService $gitService;
    private EntityManagerInterface $entityManager;

    /**
     * @var string
     */
    private mixed $workspaceRoot;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var WorkspaceGitService $gitService */
        $gitService       = $container->get(WorkspaceGitService::class);
        $this->gitService = $gitService;

        /** @var EntityManagerInterface $entityManager */
        $entityManager       = $container->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;

        $workspaceRoot = $container->getParameter('workspace_mgmt.workspace_root');
        assert(is_string($workspaceRoot));
        $this->workspaceRoot = $workspaceRoot;

        // Create a test git repository
        $this->testRepoPath = sys_get_temp_dir() . '/workspace-git-test-' . uniqid();
        mkdir($this->testRepoPath, 0777, true);

        $this->runGitCommand('init -b main');
        $this->runGitCommand('config user.name "Test User"');
        $this->runGitCommand('config user.email "test@example.com"');

        // Create some commits with bodies
        file_put_contents($this->testRepoPath . '/file1.txt', 'Content 1');
        $this->runGitCommand('add .');
        $this->runGitCommand('commit -m "Initial commit" -m "Set up the project structure"');

        file_put_contents($this->testRepoPath . '/file2.txt', 'Content 2');
        $this->runGitCommand('add .');
        $this->runGitCommand('commit -m "Add feature" -m "Implemented the main feature" -m "" -m "Related to issue #123"');

        // Create a feature branch
        $this->runGitCommand('checkout -b feature/new-feature');
        file_put_contents($this->testRepoPath . '/file3.txt', 'Content 3');
        $this->runGitCommand('add .');
        $this->runGitCommand('commit -m "Feature work"');

        // Go back to main
        $this->runGitCommand('checkout main');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testRepoPath)) {
            $this->removeDirectory($this->testRepoPath);
        }

        parent::tearDown();
    }

    public function testGetGitInfoReturnsCompleteInformation(): void
    {
        // Arrange: Create a workspace pointing to our test repo
        $workspace = $this->createTestWorkspace();

        // Act: Get git info
        $gitInfo = $this->gitService->getGitInfo($workspace, 5);

        // Assert: Current branch
        self::assertSame('main', $gitInfo->currentBranch);

        // Assert: Commits
        self::assertCount(2, $gitInfo->recentCommits);

        $firstCommit = $gitInfo->recentCommits[0];
        self::assertSame('Add feature', $firstCommit->message);
        self::assertStringContainsString('Implemented the main feature', $firstCommit->body);
        self::assertStringContainsString('Related to issue #123', $firstCommit->body);
        self::assertNotEmpty($firstCommit->hash);

        $secondCommit = $gitInfo->recentCommits[1];
        self::assertSame('Initial commit', $secondCommit->message);
        self::assertStringContainsString('Set up the project structure', $secondCommit->body);

        // Assert: Branches
        self::assertCount(2, $gitInfo->localBranches);
        self::assertContains('main', $gitInfo->localBranches);
        self::assertContains('feature/new-feature', $gitInfo->localBranches);
    }

    public function testGetGitInfoOnFeatureBranch(): void
    {
        // Arrange: Switch to feature branch
        $this->runGitCommand('checkout feature/new-feature');
        $workspace = $this->createTestWorkspace();

        // Act
        $gitInfo = $this->gitService->getGitInfo($workspace, 10);

        // Assert
        self::assertSame('feature/new-feature', $gitInfo->currentBranch);
        self::assertCount(3, $gitInfo->recentCommits);
        self::assertSame('Feature work', $gitInfo->recentCommits[0]->message);
    }

    public function testGetGitInfoWithLimitedCommits(): void
    {
        // Arrange
        $workspace = $this->createTestWorkspace();

        // Act: Request only 1 commit
        $gitInfo = $this->gitService->getGitInfo($workspace, 1);

        // Assert
        self::assertCount(1, $gitInfo->recentCommits);
        self::assertSame('Add feature', $gitInfo->recentCommits[0]->message);
    }

    private function createTestWorkspace(): Workspace
    {
        $workspace = new Workspace('test-project-' . uniqid());
        $workspace->setStatus(WorkspaceStatus::AVAILABLE_FOR_CONVERSATION);
        $workspace->setBranchName('main');

        $this->entityManager->persist($workspace);
        $this->entityManager->flush();

        $workspaceId = $workspace->getId();
        self::assertNotNull($workspaceId);

        // Move test repo to the workspace root location
        if (!is_dir($this->workspaceRoot)) {
            $result = mkdir($this->workspaceRoot, 0777, true);
            if (!$result) {
                throw new \RuntimeException('Failed to create workspace root directory: ' . $this->workspaceRoot);
            }
        }

        $targetPath = $this->workspaceRoot . '/' . $workspaceId;
        if (is_dir($targetPath)) {
            $this->removeDirectory($targetPath);
        }

        // Copy instead of rename (rename may fail across filesystems)
        $this->copyDirectory($this->testRepoPath, $targetPath);
        $this->removeDirectory($this->testRepoPath);
        $this->testRepoPath = $targetPath;

        return $workspace;
    }

    private function runGitCommand(string $command): void
    {
        $process = Process::fromShellCommandline('git ' . $command);
        $process->setWorkingDirectory($this->testRepoPath);
        $process->setTimeout(10);
        $process->mustRun();
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($source)) {
            throw new \RuntimeException('Source directory does not exist: ' . $source);
        }

        if (!is_dir($target)) {
            mkdir($target, 0777, true);
        }

        $items = scandir($source);
        if ($items === false) {
            throw new \RuntimeException('Failed to scan source directory: ' . $source);
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $sourcePath = $source . '/' . $item;
            $targetPath = $target . '/' . $item;

            if (is_dir($sourcePath)) {
                $this->copyDirectory($sourcePath, $targetPath);
            } else {
                copy($sourcePath, $targetPath);
            }
        }
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
