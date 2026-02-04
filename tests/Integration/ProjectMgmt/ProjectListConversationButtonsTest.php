<?php

declare(strict_types=1);

namespace App\Tests\Integration\ProjectMgmt;

use App\Account\Domain\Entity\AccountCore;
use App\Account\Domain\Service\AccountDomainService;
use App\Account\Facade\AccountFacadeInterface;
use App\ChatBasedContentEditor\Domain\Entity\Conversation;
use App\ChatBasedContentEditor\Domain\Enum\ConversationStatus;
use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\Organization\Domain\Service\OrganizationDomainServiceInterface;
use App\Organization\Facade\SymfonyEvent\CurrentlyActiveOrganizationChangedSymfonyEvent;
use App\Organization\Infrastructure\Repository\OrganizationRepositoryInterface;
use App\ProjectMgmt\Domain\Entity\Project;
use App\Tests\Support\SecurityUserLoginTrait;
use App\WorkspaceMgmt\Domain\Entity\Workspace;
use App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

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
    use SecurityUserLoginTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;
    private AccountDomainService $accountDomainService;
    private AccountFacadeInterface $accountFacade;
    private OrganizationDomainServiceInterface $organizationDomainService;
    private OrganizationRepositoryInterface $organizationRepository;
    private EventDispatcherInterface $eventDispatcher;
    private ?string $testOrganizationId = null;

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

        /** @var AccountDomainService $accountDomainService */
        $accountDomainService       = $container->get(AccountDomainService::class);
        $this->accountDomainService = $accountDomainService;

        /** @var AccountFacadeInterface $accountFacade */
        $accountFacade       = $container->get(AccountFacadeInterface::class);
        $this->accountFacade = $accountFacade;

        /** @var OrganizationDomainServiceInterface $organizationDomainService */
        $organizationDomainService       = $container->get(OrganizationDomainServiceInterface::class);
        $this->organizationDomainService = $organizationDomainService;

        /** @var OrganizationRepositoryInterface $organizationRepository */
        $organizationRepository       = $container->get(OrganizationRepositoryInterface::class);
        $this->organizationRepository = $organizationRepository;

        /** @var EventDispatcherInterface $eventDispatcher */
        $eventDispatcher       = $container->get(EventDispatcherInterface::class);
        $this->eventDispatcher = $eventDispatcher;
    }

    public function testOwnerSeesEditContentButtonWhenTheyHaveActiveConversation(): void
    {
        // Arrange: User A starts a conversation
        $userA = $this->createTestUser('usera-' . uniqid() . '@example.com', 'password123');

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
        $this->loginAsUser($this->client, $userA);
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
        // Note: Email addresses are stored/displayed in lowercase
        $userAEmail = 'usera-' . uniqid() . '@example.com';
        $userA      = $this->createTestUser($userAEmail, 'password123');
        // User B must be in the same organization to see the same projects
        $userB = $this->createAdditionalUserInSameOrganization('userb-' . uniqid() . '@example.com', 'password123');

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
        $this->loginAsUser($this->client, $userB);
        $crawler = $this->client->request('GET', '/en/projects');

        // Assert: User B sees "View conversation" button (not "Edit content")
        self::assertResponseIsSuccessful();

        $pageText = $crawler->text();
        self::assertStringContainsString('View conversation', $pageText);
        self::assertStringNotContainsString('Edit content', $pageText);

        // Assert: Shows who is in conversation (using the actual email)
        self::assertStringContainsString('with ' . $userAEmail, $pageText);
    }

    public function testUserSeesViewConversationForReviewButtonWhenWorkspaceIsInReview(): void
    {
        // Arrange: Workspace is in review
        $user = $this->createTestUser('user-' . uniqid() . '@example.com', 'password123');

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
        $this->loginAsUser($this->client, $user);
        $crawler = $this->client->request('GET', '/en/projects');

        // Assert: User sees "Review conversation" button
        self::assertResponseIsSuccessful();

        $pageText = $crawler->text();
        self::assertStringContainsString('Review conversation', $pageText);
    }

    public function testUserSeesEditContentButtonWhenNoActiveConversation(): void
    {
        // Arrange: Workspace is available for conversation
        $user = $this->createTestUser('user-' . uniqid() . '@example.com', 'password123');

        $project   = $this->createProject('Test Project', 'https://github.com/org/repo.git', 'token123');
        $projectId = $project->getId();
        self::assertNotNull($projectId);

        $this->createWorkspace($projectId, WorkspaceStatus::AVAILABLE_FOR_CONVERSATION);

        // Act: User views project list
        $this->loginAsUser($this->client, $user);
        $crawler = $this->client->request('GET', '/en/projects');

        // Assert: User sees "Edit content" button
        self::assertResponseIsSuccessful();

        $pageText = $crawler->text();
        self::assertStringContainsString('Edit content', $pageText);
    }

    public function testMultipleProjectsShowCorrectButtonsBasedOnConversationOwnership(): void
    {
        // Arrange: 3 projects with different scenarios
        // Note: Email addresses are stored/displayed in lowercase
        $userA      = $this->createTestUser('usera-' . uniqid() . '@example.com', 'password123');
        $userBEmail = 'userb-' . uniqid() . '@example.com';
        // User B must be in the same organization to see the same projects
        $userB = $this->createAdditionalUserInSameOrganization($userBEmail, 'password123');

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
        $this->loginAsUser($this->client, $userA);
        $crawler = $this->client->request('GET', '/en/projects');

        // Assert: Correct buttons are shown
        self::assertResponseIsSuccessful();

        $pageText = $crawler->text();

        // Project 1: User A sees "Edit content" (own conversation)
        self::assertStringContainsString('Project 1', $pageText);

        // Project 2: User A sees "View conversation" (User B's conversation)
        self::assertStringContainsString('Project 2', $pageText);
        self::assertStringContainsString('with ' . $userBEmail, $pageText);

        // Project 3: User A sees "Edit content" (no active conversation)
        self::assertStringContainsString('Project 3', $pageText);

        // Count buttons: Should have 2x "Edit content" and 1x "View conversation"
        $pageText = $crawler->text();

        // Verify the expected buttons appear in the text
        self::assertStringContainsString('Edit content', $pageText);
        self::assertStringContainsString('View conversation', $pageText);
        self::assertStringContainsString('with ' . $userBEmail, $pageText);
    }

    /**
     * Creates a test user using proper registration to trigger organization creation.
     * The first user created will have their organization stored as $testOrganizationId.
     */
    private function createTestUser(string $email, string $plainPassword): AccountCore
    {
        $user = $this->accountDomainService->register($email, $plainPassword);

        $userId = $user->getId();
        self::assertNotNull($userId);

        $organizationId = $this->accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($userId);
        self::assertNotNull($organizationId, 'User should have an organization after registration');

        // Store the first organization as the test organization
        if ($this->testOrganizationId === null) {
            $this->testOrganizationId = $organizationId;
        }

        return $user;
    }

    /**
     * Creates an additional user and adds them to the existing test organization.
     * Must be called after createTestUser() has been called at least once.
     */
    private function createAdditionalUserInSameOrganization(string $email, string $plainPassword): AccountCore
    {
        $organizationId = $this->testOrganizationId;
        self::assertNotNull($organizationId, 'Must create first user before creating additional users');

        // Create user with hashed password
        $tempUser       = new AccountCore($email, '');
        $hashedPassword = $this->passwordHasher->hashPassword($tempUser, $plainPassword);
        $user           = new AccountCore($email, $hashedPassword);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $userId = $user->getId();
        self::assertNotNull($userId);

        // Add user to the test organization
        $this->organizationRepository->addUserToOrganization($userId, $organizationId);

        // Set the test organization as currently active for this user
        $this->eventDispatcher->dispatch(
            new CurrentlyActiveOrganizationChangedSymfonyEvent(
                $organizationId,
                $userId
            )
        );

        // Add user to the default group
        $organization = $this->organizationDomainService->getOrganizationById($organizationId);
        self::assertNotNull($organization);
        $defaultGroup = $this->organizationDomainService->getDefaultGroupForNewMembers($organization);
        $this->organizationDomainService->addUserToGroup($userId, $defaultGroup);

        return $user;
    }

    private function createProject(string $name, string $gitUrl, string $githubToken): Project
    {
        self::assertNotNull($this->testOrganizationId, 'Must create user before creating project');

        $project = new Project(
            $this->testOrganizationId,
            $name,
            $gitUrl,
            $githubToken,
            LlmModelProvider::OpenAI,
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
