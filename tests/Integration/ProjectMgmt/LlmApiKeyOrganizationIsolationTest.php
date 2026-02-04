<?php

declare(strict_types=1);

namespace App\Tests\Integration\ProjectMgmt;

use App\Account\Domain\Entity\AccountCore;
use App\Account\Domain\Service\AccountDomainService;
use App\Account\Facade\AccountFacadeInterface;
use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\ProjectMgmt\Domain\Entity\Project;
use App\ProjectMgmt\Facade\Dto\ExistingLlmApiKeyDto;
use App\ProjectMgmt\Facade\ProjectMgmtFacadeInterface;
use App\Tests\Support\SecurityUserLoginTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration tests verifying LLM API keys are isolated by organization.
 *
 * These tests ensure that users can only see API keys from projects
 * belonging to their own organization, not from other organizations.
 */
final class LlmApiKeyOrganizationIsolationTest extends WebTestCase
{
    use SecurityUserLoginTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private AccountDomainService $accountDomainService;
    private AccountFacadeInterface $accountFacade;
    private ProjectMgmtFacadeInterface $projectMgmtFacade;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container    = static::getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager       = $container->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;

        /** @var AccountDomainService $accountDomainService */
        $accountDomainService       = $container->get(AccountDomainService::class);
        $this->accountDomainService = $accountDomainService;

        /** @var AccountFacadeInterface $accountFacade */
        $accountFacade       = $container->get(AccountFacadeInterface::class);
        $this->accountFacade = $accountFacade;

        /** @var ProjectMgmtFacadeInterface $projectMgmtFacade */
        $projectMgmtFacade       = $container->get(ProjectMgmtFacadeInterface::class);
        $this->projectMgmtFacade = $projectMgmtFacade;
    }

    /**
     * Test that getExistingLlmApiKeys() only returns keys from the specified organization.
     *
     * This test creates two users (each with their own organization) and projects with
     * different API keys. It verifies that when fetching existing keys for one organization,
     * the keys from the other organization are NOT returned.
     *
     * SECURITY TEST: This test should PASS after the fix is applied.
     * Before the fix, getExistingLlmApiKeys() returned keys from ALL organizations.
     */
    public function testGetExistingLlmApiKeysOnlyReturnsKeysFromSpecifiedOrganization(): void
    {
        // Arrange: Create two users, each with their own organization
        $userA           = $this->createTestUser('user-a-' . uniqid() . '@example.com', 'password123');
        $organizationIdA = $this->getOrganizationIdForUser($userA);

        $userB           = $this->createTestUser('user-b-' . uniqid() . '@example.com', 'password123');
        $organizationIdB = $this->getOrganizationIdForUser($userB);

        // Create projects with distinct API keys for each organization
        $this->createProjectWithApiKey($organizationIdA, 'Project A1', 'sk-org-a-key-one-secret');
        $this->createProjectWithApiKey($organizationIdA, 'Project A2', 'sk-org-a-key-two-secret');
        $this->createProjectWithApiKey($organizationIdB, 'Project B1', 'sk-org-b-key-one-secret');
        $this->createProjectWithApiKey($organizationIdB, 'Project B2', 'sk-org-b-key-two-secret');

        // Act: Get existing LLM API keys for organization A (now requires organizationId parameter)
        $keysForOrgA = $this->projectMgmtFacade->getExistingLlmApiKeys($organizationIdA);

        // Assert: Should only contain keys from organization A
        $apiKeysA = array_map(fn (ExistingLlmApiKeyDto $dto) => $dto->apiKey, $keysForOrgA);

        self::assertContains('sk-org-a-key-one-secret', $apiKeysA, 'Should contain org A key 1');
        self::assertContains('sk-org-a-key-two-secret', $apiKeysA, 'Should contain org A key 2');
        self::assertNotContains('sk-org-b-key-one-secret', $apiKeysA, 'Should NOT contain org B key 1');
        self::assertNotContains('sk-org-b-key-two-secret', $apiKeysA, 'Should NOT contain org B key 2');

        // Act: Get existing LLM API keys for organization B
        $keysForOrgB = $this->projectMgmtFacade->getExistingLlmApiKeys($organizationIdB);

        // Assert: Should only contain keys from organization B
        $apiKeysB = array_map(fn (ExistingLlmApiKeyDto $dto) => $dto->apiKey, $keysForOrgB);

        self::assertContains('sk-org-b-key-one-secret', $apiKeysB, 'Should contain org B key 1');
        self::assertContains('sk-org-b-key-two-secret', $apiKeysB, 'Should contain org B key 2');
        self::assertNotContains('sk-org-a-key-one-secret', $apiKeysB, 'Should NOT contain org A key 1');
        self::assertNotContains('sk-org-a-key-two-secret', $apiKeysB, 'Should NOT contain org A key 2');
    }

    /**
     * Projects with keys_visible=false (e.g. from prefab) must not appear in getExistingLlmApiKeys.
     */
    public function testGetExistingLlmApiKeysExcludesProjectsWithKeysNotVisible(): void
    {
        $user           = $this->createTestUser('user-keys-hidden-' . uniqid() . '@example.com', 'password123');
        $organizationId = $this->getOrganizationIdForUser($user);

        $this->createProjectWithApiKey($organizationId, 'Visible Project', 'sk-visible-key');
        $this->createProjectWithApiKeyAndKeysVisible($organizationId, 'Hidden Keys Project', 'sk-hidden-key', false);

        $keys = $this->projectMgmtFacade->getExistingLlmApiKeys($organizationId);

        $apiKeys = array_map(fn (ExistingLlmApiKeyDto $dto) => $dto->apiKey, $keys);
        self::assertContains('sk-visible-key', $apiKeys);
        self::assertNotContains('sk-hidden-key', $apiKeys, 'Keys from projects with keys_visible=false must not appear');
    }

    /**
     * Test that the new project form only shows API keys from the user's organization.
     *
     * This is an end-to-end test that verifies the controller correctly filters keys.
     */
    public function testNewProjectFormOnlyShowsApiKeysFromUsersOrganization(): void
    {
        // Arrange: Create two users with projects having different API keys
        $userA           = $this->createTestUser('user-a-' . uniqid() . '@example.com', 'password123');
        $organizationIdA = $this->getOrganizationIdForUser($userA);

        $userB           = $this->createTestUser('user-b-' . uniqid() . '@example.com', 'password123');
        $organizationIdB = $this->getOrganizationIdForUser($userB);

        // Create projects with API keys
        $this->createProjectWithApiKey($organizationIdA, 'My Project', 'sk-user-a-secret-key-12345');
        $this->createProjectWithApiKey($organizationIdB, 'Other Project', 'sk-user-b-secret-key-67890');

        // Act: User A visits the new project form
        $this->loginAsUser($this->client, $userA);
        $crawler = $this->client->request('GET', '/en/projects/new');

        // Assert: Page loads successfully
        self::assertResponseIsSuccessful();

        // Assert: The page should contain user A's abbreviated key (first 6 + ... + last 6)
        // sk-user-a-secret-key-12345 abbreviated = sk-use...12345
        $pageContent = $crawler->filter('body')->text();
        self::assertStringContainsString('sk-use', $pageContent, 'Should show abbreviated key from user A org');

        // Assert: The page should NOT contain user B's key or its abbreviation
        // sk-user-b-secret-key-67890 abbreviated = sk-use...67890
        // Check for the unique ending part that identifies org B's key
        self::assertStringNotContainsString('67890', $pageContent, 'Should NOT show key from user B org');
    }

    /**
     * Test that the edit project form only shows API keys from the user's organization.
     */
    public function testEditProjectFormOnlyShowsApiKeysFromUsersOrganization(): void
    {
        // Arrange: Create two users with projects having different API keys
        $userA           = $this->createTestUser('user-a-' . uniqid() . '@example.com', 'password123');
        $organizationIdA = $this->getOrganizationIdForUser($userA);

        $userB           = $this->createTestUser('user-b-' . uniqid() . '@example.com', 'password123');
        $organizationIdB = $this->getOrganizationIdForUser($userB);

        // Create projects with API keys
        $projectA = $this->createProjectWithApiKey($organizationIdA, 'My Project', 'sk-user-a-secret-key-aaaaa');
        $this->createProjectWithApiKey($organizationIdA, 'Another Project', 'sk-user-a-other-key-bbbbb');
        $this->createProjectWithApiKey($organizationIdB, 'Other Org Project', 'sk-user-b-secret-key-ccccc');

        $projectId = $projectA->getId();
        self::assertNotNull($projectId);

        // Act: User A visits their project's edit form
        $this->loginAsUser($this->client, $userA);
        $crawler = $this->client->request('GET', '/en/projects/' . $projectId . '/edit');

        // Assert: Page loads successfully
        self::assertResponseIsSuccessful();

        // Assert: The page may show the "other" key from org A for reuse (bbbbb ending)
        // but should NOT show the key from org B (ccccc ending)
        $pageContent = $crawler->filter('body')->text();
        self::assertStringNotContainsString('ccccc', $pageContent, 'Should NOT show key from user B org');
    }

    private function createTestUser(string $email, string $plainPassword): AccountCore
    {
        return $this->accountDomainService->register($email, $plainPassword);
    }

    private function getOrganizationIdForUser(AccountCore $user): string
    {
        $userId = $user->getId();
        self::assertNotNull($userId);

        $organizationId = $this->accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($userId);
        self::assertNotNull($organizationId, 'User should have an organization after registration');

        return $organizationId;
    }

    private function createProjectWithApiKey(string $organizationId, string $name, string $apiKey): Project
    {
        return $this->createProjectWithApiKeyAndKeysVisible($organizationId, $name, $apiKey, true);
    }

    private function createProjectWithApiKeyAndKeysVisible(
        string $organizationId,
        string $name,
        string $apiKey,
        bool $keysVisible
    ): Project {
        $project = new Project(
            $organizationId,
            $name,
            'https://github.com/test/repo.git',
            'ghp_testtoken123',
            LlmModelProvider::OpenAI,
            $apiKey
        );
        $project->setKeysVisible($keysVisible);
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }
}
