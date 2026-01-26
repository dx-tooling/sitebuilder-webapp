<?php

declare(strict_types=1);

namespace App\Tests\Integration\ChatBasedContentEditor;

use App\Account\Domain\Entity\AccountCore;
use App\ChatBasedContentEditor\Domain\Entity\Conversation;
use App\ChatBasedContentEditor\Domain\Enum\ConversationStatus;
use App\ProjectMgmt\Domain\Entity\Project;
use App\WorkspaceMgmt\Domain\Entity\Workspace;
use App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Throwable;

/**
 * Integration tests for ChatBasedContentEditorController to safeguard
 * conversation access restrictions per workflow requirements.
 *
 * These tests ensure:
 * - Users cannot jump between conversations for a given workflow
 * - Only the conversation owner can view their conversation
 * - ONGOING conversations are fully interactive
 * - Finished conversations are displayed in read-only mode
 */
final class ChatBasedContentEditorControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container    = static::getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager       = $container->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;

        /** @var UserPasswordHasherInterface $passwordHasher */
        $passwordHasher       = $container->get(UserPasswordHasherInterface::class);
        $this->passwordHasher = $passwordHasher;

        // Clean up test data before each test
        $this->cleanupTestData();
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
            // Tables may not exist yet on first run - that's fine
        }
    }

    public function testShowConversationDeniesAccessWhenUserIsNotOwner(): void
    {
        // Arrange: Create two users
        $ownerUser = $this->createTestUser('owner@example.com', 'password123');
        $otherUser = $this->createTestUser('other@example.com', 'password123');

        // Create a project and workspace
        $project   = $this->createProject('Test Project', 'https://github.com/org/repo.git', 'token123');
        $projectId = $project->getId();
        self::assertNotNull($projectId);
        $workspace = $this->createWorkspace($projectId, WorkspaceStatus::IN_CONVERSATION);

        // Create a conversation owned by ownerUser
        $workspaceId = $workspace->getId();
        $ownerUserId = $ownerUser->getId();
        self::assertNotNull($workspaceId);
        self::assertNotNull($ownerUserId);
        $conversation = $this->createConversation(
            $workspaceId,
            $ownerUserId,
            ConversationStatus::ONGOING
        );

        // Act: Try to access the conversation as otherUser
        $this->client->loginUser($otherUser);
        $conversationId = $conversation->getId();
        self::assertNotNull($conversationId);
        $this->client->request('GET', '/en/conversation/' . $conversationId);

        // Assert: Access denied
        self::assertResponseStatusCodeSame(403);
    }

    public function testShowConversationDisplaysFinishedConversationInReadOnlyMode(): void
    {
        // Arrange: Create a user and a finished conversation
        $user = $this->createTestUser('user@example.com', 'password123');

        $project   = $this->createProject('Test Project', 'https://github.com/org/repo.git', 'token123');
        $projectId = $project->getId();
        self::assertNotNull($projectId);
        $workspace = $this->createWorkspace($projectId, WorkspaceStatus::AVAILABLE_FOR_CONVERSATION);

        $workspaceId = $workspace->getId();
        $userId      = $user->getId();
        self::assertNotNull($workspaceId);
        self::assertNotNull($userId);
        $conversation = $this->createConversation(
            $workspaceId,
            $userId,
            ConversationStatus::FINISHED
        );

        // Act: Access the finished conversation
        $this->client->loginUser($user);
        $conversationId = $conversation->getId();
        self::assertNotNull($conversationId);
        $crawler = $this->client->request('GET', '/en/conversation/' . $conversationId);

        // Assert: Page renders successfully in read-only mode
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Content editor');

        // Assert: Read-only indicator is shown (the "session is finished" message)
        $pageText = $crawler->text();
        self::assertStringContainsString('session is finished', $pageText);

        // Assert: No chat input form (no "Make changes" button)
        self::assertSelectorNotExists('button:contains("Make changes")');

        // Assert: No heartbeat controller (check that the heartbeat div is not present)
        self::assertSelectorNotExists('[data-controller="conversation-heartbeat"]');
    }

    public function testShowConversationAllowsAccessWhenUserIsOwnerAndConversationIsOngoing(): void
    {
        // Arrange: Create a user and an ongoing conversation
        $user = $this->createTestUser('user@example.com', 'password123');

        $project   = $this->createProject('Test Project', 'https://github.com/org/repo.git', 'token123');
        $projectId = $project->getId();
        self::assertNotNull($projectId);
        $workspace = $this->createWorkspace($projectId, WorkspaceStatus::IN_CONVERSATION);

        $workspaceId = $workspace->getId();
        $userId      = $user->getId();
        self::assertNotNull($workspaceId);
        self::assertNotNull($userId);
        $conversation = $this->createConversation(
            $workspaceId,
            $userId,
            ConversationStatus::ONGOING
        );

        // Act: Access the conversation as the owner
        $this->client->loginUser($user);
        $conversationId = $conversation->getId();
        self::assertNotNull($conversationId);
        $this->client->request('GET', '/en/conversation/' . $conversationId);

        // Assert: Successfully accessed
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Content editor');
    }

    public function testShowConversationReturns404WhenConversationDoesNotExist(): void
    {
        // Arrange: Create a user
        $user = $this->createTestUser('user@example.com', 'password123');

        // Act: Try to access a non-existent conversation
        $this->client->loginUser($user);
        $this->client->request('GET', '/en/conversation/00000000-0000-0000-0000-000000000000');

        // Assert: 404 Not Found
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * This test ensures that the UI does not provide a list of past conversations.
     * The template should not render any past conversations sidebar.
     */
    public function testShowConversationDoesNotDisplayPastConversationsList(): void
    {
        // Arrange: Create a user with multiple conversations (one ongoing, one finished)
        $user = $this->createTestUser('user@example.com', 'password123');

        $project   = $this->createProject('Test Project', 'https://github.com/org/repo.git', 'token123');
        $projectId = $project->getId();
        self::assertNotNull($projectId);
        $workspace = $this->createWorkspace($projectId, WorkspaceStatus::IN_CONVERSATION);

        $workspaceId = $workspace->getId();
        $userId      = $user->getId();
        self::assertNotNull($workspaceId);
        self::assertNotNull($userId);

        // Create a finished conversation (past conversation)
        $this->createConversation(
            $workspaceId,
            $userId,
            ConversationStatus::FINISHED
        );

        // Create an ongoing conversation (current)
        $ongoingConversation = $this->createConversation(
            $workspaceId,
            $userId,
            ConversationStatus::ONGOING
        );

        // Act: Access the ongoing conversation
        $this->client->loginUser($user);
        $conversationId = $ongoingConversation->getId();
        self::assertNotNull($conversationId);
        $crawler = $this->client->request('GET', '/en/conversation/' . $conversationId);

        // Assert: No "Past Conversations" section in the rendered HTML
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Past Conversations', $crawler->text());
        self::assertSelectorNotExists('h2:contains("Past Conversations")');
    }

    private function createTestUser(string $email, string $plainPassword): AccountCore
    {
        // Create user with temporary empty hash
        $user = new AccountCore($email, '');

        // Hash the password using the hasher
        $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);

        // Create user with correct hash (passwordHash is readonly)
        $user = new AccountCore($email, $hashedPassword);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createProject(string $name, string $gitUrl, string $githubToken): Project
    {
        $project = new Project(
            $name,
            $gitUrl,
            $githubToken,
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

    private function createConversation(
        string             $workspaceId,
        string             $userId,
        ConversationStatus $status
    ): Conversation {
        $workspace = $this->entityManager->find(Workspace::class, $workspaceId);
        if ($workspace === null) {
            throw new RuntimeException('Workspace not found');
        }

        $conversation = new Conversation($workspaceId, $userId, '/tmp/workspace-' . $workspaceId);
        $conversation->setStatus($status);

        $this->entityManager->persist($conversation);
        $this->entityManager->flush();

        return $conversation;
    }

    public function testHeartbeatUpdatesLastActivityAt(): void
    {
        // Arrange: Create a user and an ongoing conversation
        $user = $this->createTestUser('user@example.com', 'password123');

        $project   = $this->createProject('Test Project', 'https://github.com/org/repo.git', 'token123');
        $projectId = $project->getId();
        self::assertNotNull($projectId);
        $workspace = $this->createWorkspace($projectId, WorkspaceStatus::IN_CONVERSATION);

        $workspaceId = $workspace->getId();
        $userId      = $user->getId();
        self::assertNotNull($workspaceId);
        self::assertNotNull($userId);
        $conversation = $this->createConversation(
            $workspaceId,
            $userId,
            ConversationStatus::ONGOING
        );

        // Assert: lastActivityAt is null initially
        self::assertNull($conversation->getLastActivityAt());

        // Act: Send heartbeat
        $this->client->loginUser($user);
        $conversationId = $conversation->getId();
        self::assertNotNull($conversationId);
        $this->client->request('POST', '/en/conversation/' . $conversationId . '/heartbeat');

        // Assert: Heartbeat successful
        self::assertResponseIsSuccessful();
        $responseData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($responseData);
        self::assertTrue($responseData['success']);

        // Assert: lastActivityAt is now set
        $this->entityManager->clear();
        $updatedConversation = $this->entityManager->find(Conversation::class, $conversationId);
        self::assertNotNull($updatedConversation);
        self::assertNotNull($updatedConversation->getLastActivityAt());
    }

    public function testHeartbeatDeniesAccessWhenUserIsNotOwner(): void
    {
        // Arrange: Create two users
        $ownerUser = $this->createTestUser('owner@example.com', 'password123');
        $otherUser = $this->createTestUser('other@example.com', 'password123');

        $project   = $this->createProject('Test Project', 'https://github.com/org/repo.git', 'token123');
        $projectId = $project->getId();
        self::assertNotNull($projectId);
        $workspace = $this->createWorkspace($projectId, WorkspaceStatus::IN_CONVERSATION);

        $workspaceId = $workspace->getId();
        $ownerUserId = $ownerUser->getId();
        self::assertNotNull($workspaceId);
        self::assertNotNull($ownerUserId);
        $conversation = $this->createConversation(
            $workspaceId,
            $ownerUserId,
            ConversationStatus::ONGOING
        );

        // Act: Try to send heartbeat as other user
        $this->client->loginUser($otherUser);
        $conversationId = $conversation->getId();
        self::assertNotNull($conversationId);
        $this->client->request('POST', '/en/conversation/' . $conversationId . '/heartbeat');

        // Assert: Access denied
        self::assertResponseStatusCodeSame(403);
    }

    public function testHeartbeatReturnsErrorWhenConversationIsNotOngoing(): void
    {
        // Arrange: Create a user and a finished conversation
        $user = $this->createTestUser('user@example.com', 'password123');

        $project   = $this->createProject('Test Project', 'https://github.com/org/repo.git', 'token123');
        $projectId = $project->getId();
        self::assertNotNull($projectId);
        $workspace = $this->createWorkspace($projectId, WorkspaceStatus::AVAILABLE_FOR_CONVERSATION);

        $workspaceId = $workspace->getId();
        $userId      = $user->getId();
        self::assertNotNull($workspaceId);
        self::assertNotNull($userId);
        $conversation = $this->createConversation(
            $workspaceId,
            $userId,
            ConversationStatus::FINISHED
        );

        // Act: Try to send heartbeat on finished conversation
        $this->client->loginUser($user);
        $conversationId = $conversation->getId();
        self::assertNotNull($conversationId);
        $this->client->request('POST', '/en/conversation/' . $conversationId . '/heartbeat');

        // Assert: Bad request
        self::assertResponseStatusCodeSame(400);
    }

    public function testHeartbeatReturns404WhenConversationDoesNotExist(): void
    {
        // Arrange: Create a user
        $user = $this->createTestUser('user@example.com', 'password123');

        // Act: Try to send heartbeat to non-existent conversation
        $this->client->loginUser($user);
        $this->client->request('POST', '/en/conversation/00000000-0000-0000-0000-000000000000/heartbeat');

        // Assert: 404 Not Found
        self::assertResponseStatusCodeSame(404);
    }
}
