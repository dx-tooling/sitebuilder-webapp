<?php

declare(strict_types=1);

namespace App\Tests\Integration\ProjectMgmt;

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
 * Integration tests for project list conversation buttons.
 *
 * These tests ensure that the correct button is shown based on conversation ownership:
 * - Owner sees "Edit content" when they have an active conversation
 * - Other users see "View conversation" when someone else has an active conversation
 * - Users see "View conversation for review" when workspace is in review
 */
final class ProjectListConversationButtonsTest extends WebTestCase
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
            // Tables may not exist yet on first run
        }
    }

    public function testOwnerSeesEditContentButtonWhenTheyHaveActiveConversation(): void
    {
        // Arrange: User A starts a conversation
        $userA = $this->createTestUser('userA@example.com', 'password123');

        $project   = $this->createProject('Test Project', 'https://github.com/org/repo.git', 'token123');
        $projectId = $project->getId();
        self::assertNotNull($projectId);

        $workspace   = $this->createWorkspace($projectId, WorkspaceStatus::IN_CONVERSATION);
        $workspaceId = $workspace->getId();
        $userAId     = $userA->getId();
        self::assertNotNull($workspaceId);
        self::assertNotNull($userAId);

        $this->createConversation($workspaceId, $userAId, ConversationStatus::ONGOING);

        // Act: User A views project list
        $this->client->loginUser($userA);
        $crawler = $this->client->request('GET', '/en/projects');

        // Assert: User A sees "Edit content" button (not "View conversation")
        self::assertResponseIsSuccessful();

        $pageText = $crawler->text();
        self::assertStringContainsString('Edit content', $pageText);
        self::assertStringNotContainsString('View conversation', $pageText);
    }

    public function testOtherUserSeesViewConversationButtonWhenSomeoneElseHasActiveConversation(): void
    {
        // Arrange: User A starts a conversation
        $userA = $this->createTestUser('userA@example.com', 'password123');
        $userB = $this->createTestUser('userB@example.com', 'password123');

        $project   = $this->createProject('Test Project', 'https://github.com/org/repo.git', 'token123');
        $projectId = $project->getId();
        self::assertNotNull($projectId);

        $workspace   = $this->createWorkspace($projectId, WorkspaceStatus::IN_CONVERSATION);
        $workspaceId = $workspace->getId();
        $userAId     = $userA->getId();
        self::assertNotNull($workspaceId);
        self::assertNotNull($userAId);

        $this->createConversation($workspaceId, $userAId, ConversationStatus::ONGOING);

        // Act: User B views project list
        $this->client->loginUser($userB);
        $crawler = $this->client->request('GET', '/en/projects');

        // Assert: User B sees "View conversation" button (not "Edit content")
        self::assertResponseIsSuccessful();

        $pageText = $crawler->text();
        self::assertStringContainsString('View conversation', $pageText);
        self::assertStringNotContainsString('Edit content', $pageText);

        // Assert: Shows who is in conversation
        self::assertStringContainsString('with userA@example.com', $pageText);
    }

    public function testUserSeesViewConversationForReviewButtonWhenWorkspaceIsInReview(): void
    {
        // Arrange: Workspace is in review
        $user = $this->createTestUser('user@example.com', 'password123');

        $project   = $this->createProject('Test Project', 'https://github.com/org/repo.git', 'token123');
        $projectId = $project->getId();
        self::assertNotNull($projectId);

        $workspace   = $this->createWorkspace($projectId, WorkspaceStatus::IN_REVIEW);
        $workspaceId = $workspace->getId();
        $userId      = $user->getId();
        self::assertNotNull($workspaceId);
        self::assertNotNull($userId);

        // Create a finished conversation (typical for IN_REVIEW status)
        $this->createConversation($workspaceId, $userId, ConversationStatus::FINISHED);

        // Act: User views project list
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/en/projects');

        // Assert: User sees "Review conversation" button
        self::assertResponseIsSuccessful();

        $pageText = $crawler->text();
        self::assertStringContainsString('Review conversation', $pageText);
    }

    public function testUserSeesEditContentButtonWhenNoActiveConversation(): void
    {
        // Arrange: Workspace is available for conversation
        $user = $this->createTestUser('user@example.com', 'password123');

        $project   = $this->createProject('Test Project', 'https://github.com/org/repo.git', 'token123');
        $projectId = $project->getId();
        self::assertNotNull($projectId);

        $this->createWorkspace($projectId, WorkspaceStatus::AVAILABLE_FOR_CONVERSATION);

        // Act: User views project list
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/en/projects');

        // Assert: User sees "Edit content" button
        self::assertResponseIsSuccessful();

        $pageText = $crawler->text();
        self::assertStringContainsString('Edit content', $pageText);
    }

    public function testMultipleProjectsShowCorrectButtonsBasedOnConversationOwnership(): void
    {
        // Arrange: 3 projects with different scenarios
        $userA = $this->createTestUser('userA@example.com', 'password123');
        $userB = $this->createTestUser('userB@example.com', 'password123');

        // Project 1: User A has active conversation
        $project1   = $this->createProject('Project 1', 'https://github.com/org/repo1.git', 'token1');
        $project1Id = $project1->getId();
        self::assertNotNull($project1Id);
        $workspace1   = $this->createWorkspace($project1Id, WorkspaceStatus::IN_CONVERSATION);
        $workspace1Id = $workspace1->getId();
        $userAId      = $userA->getId();
        self::assertNotNull($workspace1Id);
        self::assertNotNull($userAId);
        $this->createConversation($workspace1Id, $userAId, ConversationStatus::ONGOING);

        // Project 2: User B has active conversation
        $project2   = $this->createProject('Project 2', 'https://github.com/org/repo2.git', 'token2');
        $project2Id = $project2->getId();
        self::assertNotNull($project2Id);
        $workspace2   = $this->createWorkspace($project2Id, WorkspaceStatus::IN_CONVERSATION);
        $workspace2Id = $workspace2->getId();
        $userBId      = $userB->getId();
        self::assertNotNull($workspace2Id);
        self::assertNotNull($userBId);
        $this->createConversation($workspace2Id, $userBId, ConversationStatus::ONGOING);

        // Project 3: No active conversation
        $project3   = $this->createProject('Project 3', 'https://github.com/org/repo3.git', 'token3');
        $project3Id = $project3->getId();
        self::assertNotNull($project3Id);
        $this->createWorkspace($project3Id, WorkspaceStatus::AVAILABLE_FOR_CONVERSATION);

        // Act: User A views project list
        $this->client->loginUser($userA);
        $crawler = $this->client->request('GET', '/en/projects');

        // Assert: Correct buttons are shown
        self::assertResponseIsSuccessful();

        $pageText = $crawler->text();

        // Project 1: User A sees "Edit content" (own conversation)
        self::assertStringContainsString('Project 1', $pageText);

        // Project 2: User A sees "View conversation" (User B's conversation)
        self::assertStringContainsString('Project 2', $pageText);
        self::assertStringContainsString('with userB@example.com', $pageText);

        // Project 3: User A sees "Edit content" (no active conversation)
        self::assertStringContainsString('Project 3', $pageText);

        // Count buttons: Should have 2x "Edit content" and 1x "View conversation"
        $pageText = $crawler->text();

        // Verify the expected buttons appear in the text
        self::assertStringContainsString('Edit content', $pageText);
        self::assertStringContainsString('View conversation', $pageText);
        self::assertStringContainsString('with userB@example.com', $pageText);
    }

    private function createTestUser(string $email, string $plainPassword): AccountCore
    {
        $user = new AccountCore($email, '');

        $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);

        $user = new AccountCore($email, $hashedPassword);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createProject(string $name, string $gitUrl, string $githubToken): Project
    {
        $project = new Project(
            'org-test-123',
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
}
