<?php

declare(strict_types=1);

namespace App\Tests\Integration\ChatBasedContentEditor;

use App\Account\Domain\Entity\AccountCore;
use App\ChatBasedContentEditor\Domain\Entity\Conversation;
use App\ChatBasedContentEditor\Domain\Enum\ConversationStatus;
use App\ChatBasedContentEditor\Facade\ChatBasedContentEditorFacadeInterface;
use App\ProjectMgmt\Domain\Entity\Project;
use App\WorkspaceMgmt\Domain\Entity\Workspace;
use App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Throwable;

/**
 * Integration tests for ChatBasedContentEditorFacade.
 * Uses MockClock for time-based testing of stale conversation detection.
 */
final class ChatBasedContentEditorFacadeTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ChatBasedContentEditorFacadeInterface $facade;
    private MockClock $mockClock;

    protected function setUp(): void
    {
        // Set up MockClock BEFORE booting kernel
        $this->mockClock = new MockClock('2026-01-23 10:00:00');
        Clock::set($this->mockClock);

        self::bootKernel();
        $container = static::getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager       = $container->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;

        /** @var ChatBasedContentEditorFacadeInterface $facade */
        $facade       = $container->get(ChatBasedContentEditorFacadeInterface::class);
        $this->facade = $facade;

        $this->cleanupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    private function cleanupTestData(): void
    {
        $connection = $this->entityManager->getConnection();

        try {
            $connection->executeStatement('DELETE FROM conversations');
            $connection->executeStatement('DELETE FROM workspaces');
            $connection->executeStatement('DELETE FROM projects');
            $connection->executeStatement('DELETE FROM account_cores');
        } catch (Throwable) {
            // Tables may not exist yet on first run
        }
    }

    public function testReleaseStaleConversationsReleasesConversationsOlderThanTimeout(): void
    {
        // Arrange: Create a user, project, and workspace
        $user      = $this->createTestUser('user@example.com');
        $project   = $this->createProject('Test Project');
        $projectId = $project->getId();
        self::assertNotNull($projectId);

        $workspace   = $this->createWorkspace($projectId, WorkspaceStatus::IN_CONVERSATION);
        $workspaceId = $workspace->getId();
        $userId      = $user->getId();
        self::assertNotNull($workspaceId);
        self::assertNotNull($userId);

        // Create a conversation at 10:00
        $conversation   = $this->createConversation($workspaceId, $userId);
        $conversationId = $conversation->getId();
        self::assertNotNull($conversationId);

        // Update last activity at 10:00
        $conversation->updateLastActivity();
        $this->entityManager->flush();

        // Time travel to 10:06 (6 minutes later, past the 5-minute timeout)
        $this->mockClock->modify('+6 minutes');

        // Act: Release stale conversations
        $releasedWorkspaceIds = $this->facade->releaseStaleConversations(5);

        // Assert: The conversation should be released
        self::assertCount(1, $releasedWorkspaceIds);
        self::assertContains($workspaceId, $releasedWorkspaceIds);

        // Verify conversation is now FINISHED
        $this->entityManager->clear();
        $updatedConversation = $this->entityManager->find(Conversation::class, $conversationId);
        self::assertNotNull($updatedConversation);
        self::assertSame(ConversationStatus::FINISHED, $updatedConversation->getStatus());

        // Verify workspace is now AVAILABLE_FOR_CONVERSATION
        $updatedWorkspace = $this->entityManager->find(Workspace::class, $workspaceId);
        self::assertNotNull($updatedWorkspace);
        self::assertSame(WorkspaceStatus::AVAILABLE_FOR_CONVERSATION, $updatedWorkspace->getStatus());
    }

    public function testReleaseStaleConversationsDoesNotReleaseRecentConversations(): void
    {
        // Arrange: Create a user, project, and workspace
        $user      = $this->createTestUser('user@example.com');
        $project   = $this->createProject('Test Project');
        $projectId = $project->getId();
        self::assertNotNull($projectId);

        $workspace   = $this->createWorkspace($projectId, WorkspaceStatus::IN_CONVERSATION);
        $workspaceId = $workspace->getId();
        $userId      = $user->getId();
        self::assertNotNull($workspaceId);
        self::assertNotNull($userId);

        // Create a conversation at 10:00
        $conversation   = $this->createConversation($workspaceId, $userId);
        $conversationId = $conversation->getId();
        self::assertNotNull($conversationId);

        // Update last activity at 10:00
        $conversation->updateLastActivity();
        $this->entityManager->flush();

        // Time travel to 10:03 (only 3 minutes later, within the 5-minute timeout)
        $this->mockClock->modify('+3 minutes');

        // Act: Release stale conversations
        $releasedWorkspaceIds = $this->facade->releaseStaleConversations(5);

        // Assert: No conversations should be released
        self::assertCount(0, $releasedWorkspaceIds);

        // Verify conversation is still ONGOING
        $this->entityManager->clear();
        $updatedConversation = $this->entityManager->find(Conversation::class, $conversationId);
        self::assertNotNull($updatedConversation);
        self::assertSame(ConversationStatus::ONGOING, $updatedConversation->getStatus());

        // Verify workspace is still IN_CONVERSATION
        $updatedWorkspace = $this->entityManager->find(Workspace::class, $workspaceId);
        self::assertNotNull($updatedWorkspace);
        self::assertSame(WorkspaceStatus::IN_CONVERSATION, $updatedWorkspace->getStatus());
    }

    public function testReleaseStaleConversationsReleasesConversationWithNullLastActivityAt(): void
    {
        // Arrange: Create a user, project, and workspace
        $user      = $this->createTestUser('user@example.com');
        $project   = $this->createProject('Test Project');
        $projectId = $project->getId();
        self::assertNotNull($projectId);

        $workspace   = $this->createWorkspace($projectId, WorkspaceStatus::IN_CONVERSATION);
        $workspaceId = $workspace->getId();
        $userId      = $user->getId();
        self::assertNotNull($workspaceId);
        self::assertNotNull($userId);

        // Create a conversation at 10:00 but DON'T update lastActivityAt (simulates legacy/new conversation)
        $conversation   = $this->createConversation($workspaceId, $userId);
        $conversationId = $conversation->getId();
        self::assertNotNull($conversationId);

        // Verify lastActivityAt is null
        self::assertNull($conversation->getLastActivityAt());

        // Time travel to 10:06 (6 minutes later - createdAt should be used as fallback)
        $this->mockClock->modify('+6 minutes');

        // Act: Release stale conversations
        $releasedWorkspaceIds = $this->facade->releaseStaleConversations(5);

        // Assert: The conversation should be released (based on createdAt)
        self::assertCount(1, $releasedWorkspaceIds);
        self::assertContains($workspaceId, $releasedWorkspaceIds);

        // Verify conversation is now FINISHED
        $this->entityManager->clear();
        $updatedConversation = $this->entityManager->find(Conversation::class, $conversationId);
        self::assertNotNull($updatedConversation);
        self::assertSame(ConversationStatus::FINISHED, $updatedConversation->getStatus());
    }

    private function createTestUser(string $email): AccountCore
    {
        $user = new AccountCore($email, 'hashed-password');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createProject(string $name): Project
    {
        $project = new Project(
            'org-test-123',
            $name,
            'https://github.com/org/repo.git',
            'token123',
            \App\LlmContentEditor\Facade\Enum\LlmModelProvider::OpenAI,
            'sk-test-key'
        );
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    private function createWorkspace(string $projectId, WorkspaceStatus $status): Workspace
    {
        $workspace = new Workspace($projectId);
        $workspace->setStatus($status);
        $this->entityManager->persist($workspace);
        $this->entityManager->flush();

        return $workspace;
    }

    private function createConversation(string $workspaceId, string $userId): Conversation
    {
        $conversation = new Conversation($workspaceId, $userId, '/tmp/workspace-' . $workspaceId);
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();

        return $conversation;
    }
}
