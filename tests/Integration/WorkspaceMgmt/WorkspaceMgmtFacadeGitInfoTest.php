<?php

declare(strict_types=1);

namespace App\Tests\Integration\WorkspaceMgmt;

use App\WorkspaceMgmt\Domain\Entity\Workspace;
use App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus;
use App\WorkspaceMgmt\Facade\WorkspaceMgmtFacadeInterface;
use Doctrine\ORM\EntityManagerInterface;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

use function assert;
use function is_dir;
use function is_string;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Application test for WorkspaceMgmtFacade::getGitInfo.
 * Tests the full flow from facade through service to adapter.
 */
final class WorkspaceMgmtFacadeGitInfoTest extends KernelTestCase
{
    private string $testRepoPath;
    private WorkspaceMgmtFacadeInterface $facade;
    private EntityManagerInterface $entityManager;

    /**
     * @var string
     */
    private mixed $workspaceRoot;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var WorkspaceMgmtFacadeInterface $facade */
        $facade       = $container->get(WorkspaceMgmtFacadeInterface::class);
        $this->facade = $facade;

        /** @var EntityManagerInterface $entityManager */
        $entityManager       = $container->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;

        $workspaceRoot = $container->getParameter('workspace_mgmt.workspace_root');
        assert(is_string($workspaceRoot));
        $this->workspaceRoot = $workspaceRoot;

        // Create a test git repository
        $this->testRepoPath = sys_get_temp_dir() . '/facade-git-test-' . uniqid();
        mkdir($this->testRepoPath, 0777, true);

        $this->runGitCommand('init');
        $this->runGitCommand('config user.name "Test User"');
        $this->runGitCommand('config user.email "test@example.com"');

        // Create commits with various body formats
        file_put_contents($this->testRepoPath . '/readme.md', '# Project');
        $this->runGitCommand('add .');
        $this->runGitCommand('commit -m "Initial commit" -m "Project setup and initialization"');

        file_put_contents($this->testRepoPath . '/feature.txt', 'Feature content');
        $this->runGitCommand('add .');
        $this->runGitCommand('commit -m "Add main feature" -m "Implemented core functionality" -m "" -m "Fixes #42"');

        file_put_contents($this->testRepoPath . '/docs.txt', 'Documentation');
        $this->runGitCommand('add .');
        $this->runGitCommand('commit -m "Add documentation"');

        // Create development and staging branches
        $this->runGitCommand('checkout -b development');
        file_put_contents($this->testRepoPath . '/dev.txt', 'Dev work');
        $this->runGitCommand('add .');
        $this->runGitCommand('commit -m "Development work"');

        $this->runGitCommand('checkout main');
        $this->runGitCommand('checkout -b staging');
        $this->runGitCommand('checkout main');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testRepoPath)) {
            $this->removeDirectory($this->testRepoPath);
        }

        parent::tearDown();
    }

    public function testGetGitInfoReturnsCompleteWorkspaceGitInfo(): void
    {
        // Arrange: Create a workspace
        $workspace   = $this->createTestWorkspace();
        $workspaceId = $workspace->getId();
        self::assertNotNull($workspaceId);

        // Act: Get git info via facade
        $gitInfo = $this->facade->getGitInfo($workspaceId);

        // Assert: Current branch
        self::assertSame('main', $gitInfo->currentBranch);

        // Assert: Recent commits (should have 3 commits on main)
        self::assertCount(3, $gitInfo->recentCommits);

        // Check most recent commit (Add documentation)
        $latestCommit = $gitInfo->recentCommits[0];
        self::assertSame('Add documentation', $latestCommit->message);
        self::assertSame('', $latestCommit->body);
        self::assertNotEmpty($latestCommit->hash);

        // Check second commit (Add main feature with body)
        $secondCommit = $gitInfo->recentCommits[1];
        self::assertSame('Add main feature', $secondCommit->message);
        self::assertStringContainsString('Implemented core functionality', $secondCommit->body);
        self::assertStringContainsString('Fixes #42', $secondCommit->body);

        // Check third commit (Initial commit)
        $thirdCommit = $gitInfo->recentCommits[2];
        self::assertSame('Initial commit', $thirdCommit->message);
        self::assertStringContainsString('Project setup and initialization', $thirdCommit->body);

        // Assert: All local branches
        self::assertCount(3, $gitInfo->localBranches);
        self::assertContains('main', $gitInfo->localBranches);
        self::assertContains('development', $gitInfo->localBranches);
        self::assertContains('staging', $gitInfo->localBranches);
    }

    public function testGetGitInfoRespectsCommitLimit(): void
    {
        // Arrange
        $workspace   = $this->createTestWorkspace();
        $workspaceId = $workspace->getId();
        self::assertNotNull($workspaceId);

        // Act: Request only 2 commits
        $gitInfo = $this->facade->getGitInfo($workspaceId, 2);

        // Assert: Only 2 commits returned
        self::assertCount(2, $gitInfo->recentCommits);
        self::assertSame('Add documentation', $gitInfo->recentCommits[0]->message);
        self::assertSame('Add main feature', $gitInfo->recentCommits[1]->message);
    }

    public function testGetGitInfoReturnsCorrectBranchWhenOnDevelopment(): void
    {
        // Arrange: Switch to development branch
        $this->runGitCommand('checkout development');
        $workspace   = $this->createTestWorkspace();
        $workspaceId = $workspace->getId();
        self::assertNotNull($workspaceId);

        // Act
        $gitInfo = $this->facade->getGitInfo($workspaceId);

        // Assert: Current branch is development
        self::assertSame('development', $gitInfo->currentBranch);

        // Assert: 4 commits on development branch (3 from main + 1 dev commit)
        self::assertCount(4, $gitInfo->recentCommits);
        self::assertSame('Development work', $gitInfo->recentCommits[0]->message);
    }

    public function testGetGitInfoCommitHashesAreValid(): void
    {
        // Arrange
        $workspace   = $this->createTestWorkspace();
        $workspaceId = $workspace->getId();
        self::assertNotNull($workspaceId);

        // Act
        $gitInfo = $this->facade->getGitInfo($workspaceId);

        // Assert: All commit hashes are valid (40 character hex strings)
        foreach ($gitInfo->recentCommits as $commit) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $commit->hash);
        }
    }

    public function testGetGitInfoCommitTimestampsAreValid(): void
    {
        // Arrange
        $workspace   = $this->createTestWorkspace();
        $workspaceId = $workspace->getId();
        self::assertNotNull($workspaceId);

        // Act
        $gitInfo = $this->facade->getGitInfo($workspaceId);

        // Assert: All timestamps are recent (within last hour - tests should run fast)
        foreach ($gitInfo->recentCommits as $commit) {
            $now  = DateAndTimeService::getDateTimeImmutable();
            $diff = $now->getTimestamp() - $commit->committedAt->getTimestamp();
            self::assertLessThan(3600, $diff, 'Commit timestamp should be recent');
        }
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
        $targetPath = $this->workspaceRoot . '/' . $workspaceId;
        if (is_dir($targetPath)) {
            $this->removeDirectory($targetPath);
        }
        rename($this->testRepoPath, $targetPath);
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
