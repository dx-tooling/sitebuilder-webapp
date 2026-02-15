<?php

declare(strict_types=1);

namespace App\Tests\Integration\ProjectMgmt;

use App\Account\Domain\Entity\AccountCore;
use App\Account\Domain\Service\AccountDomainService;
use App\Account\Facade\AccountFacadeInterface;
use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\ProjectMgmt\Domain\Entity\Project;
use App\Tests\Support\SecurityUserLoginTrait;
use App\WorkspaceMgmt\Domain\Entity\Workspace;
use App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration tests for project soft delete and permanent delete functionality.
 */
final class ProjectDeleteTest extends WebTestCase
{
    use SecurityUserLoginTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private AccountDomainService $accountDomainService;
    private AccountFacadeInterface $accountFacade;
    private ?string $testOrganizationId = null;

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
    }

    public function testSoftDeleteRemovesProjectFromList(): void
    {
        // Arrange
        $user    = $this->createTestUser('user-' . uniqid() . '@example.com', 'password123');
        $project = $this->createProject('Test Project');

        $projectId = $project->getId();
        self::assertNotNull($projectId);

        // Act: Log in and visit the projects page to get CSRF token, then soft delete
        $this->loginAsUser($this->client, $user);
        $crawler = $this->client->request('GET', '/en/projects');

        // Find and submit the delete form
        $deleteForm = $crawler->filter('form[action="/en/projects/' . $projectId . '/delete"]')->form();
        $this->client->submit($deleteForm);

        // Assert: Redirects to list
        self::assertResponseRedirects('/en/projects');

        // Assert: Project is marked as deleted but still exists in DB
        $this->entityManager->clear();
        $deletedProject = $this->entityManager->find(Project::class, $projectId);
        self::assertNotNull($deletedProject);
        self::assertTrue($deletedProject->isDeleted());
        self::assertNotNull($deletedProject->getDeletedAt());
    }

    public function testSoftDeletedProjectNotShownInActiveList(): void
    {
        // Arrange
        $user           = $this->createTestUser('user-' . uniqid() . '@example.com', 'password123');
        $activeProject  = $this->createProject('Active Project');
        $deletedProject = $this->createProject('Deleted Project');

        $deletedProjectId = $deletedProject->getId();
        self::assertNotNull($deletedProjectId);

        // Soft delete one project
        $deletedProject->markAsDeleted();
        $this->entityManager->flush();

        // Act: Visit project list
        $this->loginAsUser($this->client, $user);
        $crawler = $this->client->request('GET', '/en/projects');

        // Assert
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h3', 'Active Project');
        self::assertSelectorTextNotContains('.space-y-4', 'Deleted Project');
    }

    public function testSoftDeletedProjectShownInDeletedSection(): void
    {
        // Arrange
        $user           = $this->createTestUser('user-' . uniqid() . '@example.com', 'password123');
        $deletedProject = $this->createProject('Deleted Project');

        $deletedProject->markAsDeleted();
        $this->entityManager->flush();

        // Act: Visit project list
        $this->loginAsUser($this->client, $user);
        $crawler = $this->client->request('GET', '/en/projects');

        // Assert: Deleted projects section exists and contains the project
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('details summary');
        self::assertSelectorTextContains('details', 'Deleted Project');
    }

    public function testPermanentDeleteRemovesProjectFromDatabase(): void
    {
        // Arrange
        $user    = $this->createTestUser('user-' . uniqid() . '@example.com', 'password123');
        $project = $this->createProject('Project To Delete');

        $projectId = $project->getId();
        self::assertNotNull($projectId);

        // First soft delete
        $project->markAsDeleted();
        $this->entityManager->flush();

        // Act: Visit projects page and find permanent delete form in deleted section
        $this->loginAsUser($this->client, $user);
        $crawler = $this->client->request('GET', '/en/projects');

        // Find and submit the permanent delete form
        $deleteForm = $crawler->filter('form[action="/en/projects/' . $projectId . '/permanently-delete"]')->form();
        $this->client->submit($deleteForm);

        // Assert: Redirects to list
        self::assertResponseRedirects('/en/projects');

        // Assert: Project no longer exists in DB
        $this->entityManager->clear();
        $deletedProject = $this->entityManager->find(Project::class, $projectId);
        self::assertNull($deletedProject);
    }

    public function testPermanentDeleteRequiresSoftDeletedProject(): void
    {
        // Arrange: Create an active (not soft-deleted) project
        $user    = $this->createTestUser('user-' . uniqid() . '@example.com', 'password123');
        $project = $this->createProject('Active Project');

        $projectId = $project->getId();
        self::assertNotNull($projectId);

        // Act: Log in and try to permanently delete without soft delete first
        // Note: Controller checks project state before CSRF validation, so we use a dummy token
        $this->loginAsUser($this->client, $user);
        $this->client->request('POST', '/en/projects/' . $projectId . '/permanently-delete', [
            '_csrf_token' => 'dummy-token',
        ]);

        // Assert: Returns 404 (project not found in deleted state)
        self::assertResponseStatusCodeSame(404);

        $this->entityManager->clear();
        $unchangedProject = $this->entityManager->find(Project::class, $projectId);
        self::assertNotNull($unchangedProject);
        self::assertFalse($unchangedProject->isDeleted());
        self::assertNull($unchangedProject->getDeletedAt());
    }

    public function testPermanentDeleteAlsoDeletesWorkspace(): void
    {
        // Arrange
        $user    = $this->createTestUser('user-' . uniqid() . '@example.com', 'password123');
        $project = $this->createProject('Project With Workspace');

        $projectId = $project->getId();
        self::assertNotNull($projectId);

        $workspace   = $this->createWorkspace($projectId);
        $workspaceId = $workspace->getId();
        self::assertNotNull($workspaceId);

        // Soft delete the project
        $project->markAsDeleted();
        $this->entityManager->flush();

        // Act: Visit projects page and find permanent delete form
        $this->loginAsUser($this->client, $user);
        $crawler = $this->client->request('GET', '/en/projects');

        // Find and submit the permanent delete form
        $deleteForm = $crawler->filter('form[action="/en/projects/' . $projectId . '/permanently-delete"]')->form();
        $this->client->submit($deleteForm);

        // Assert: Both project and workspace are deleted
        self::assertResponseRedirects('/en/projects');

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Project::class, $projectId));
        self::assertNull($this->entityManager->find(Workspace::class, $workspaceId));
    }

    public function testCannotEditSoftDeletedProject(): void
    {
        // Arrange
        $user    = $this->createTestUser('user-' . uniqid() . '@example.com', 'password123');
        $project = $this->createProject('Deleted Project');

        $projectId = $project->getId();
        self::assertNotNull($projectId);

        $project->markAsDeleted();
        $this->entityManager->flush();

        // Act: Try to access edit page
        $this->loginAsUser($this->client, $user);
        $this->client->request('GET', '/en/projects/' . $projectId . '/edit');

        // Assert: Returns 404
        self::assertResponseStatusCodeSame(404);
    }

    public function testCannotDeleteAlreadyDeletedProject(): void
    {
        // Arrange
        $user    = $this->createTestUser('user-' . uniqid() . '@example.com', 'password123');
        $project = $this->createProject('Already Deleted Project');

        $projectId = $project->getId();
        self::assertNotNull($projectId);

        $project->markAsDeleted();
        $this->entityManager->flush();

        // Act: Log in and try to soft delete again
        // Note: Controller checks project state before CSRF validation, so we use a dummy token
        $this->loginAsUser($this->client, $user);
        $this->client->request('POST', '/en/projects/' . $projectId . '/delete', [
            '_csrf_token' => 'dummy-token',
        ]);

        // Assert: Returns 404 (already deleted)
        self::assertResponseStatusCodeSame(404);

        $this->entityManager->clear();
        $unchangedProject = $this->entityManager->find(Project::class, $projectId);
        self::assertNotNull($unchangedProject);
        self::assertTrue($unchangedProject->isDeleted());
        self::assertNotNull($unchangedProject->getDeletedAt());
    }

    public function testRestoreProjectMakesItActiveAgain(): void
    {
        // Arrange
        $user    = $this->createTestUser('user-' . uniqid() . '@example.com', 'password123');
        $project = $this->createProject('Deleted Project');

        $projectId = $project->getId();
        self::assertNotNull($projectId);

        // Soft delete the project
        $project->markAsDeleted();
        $this->entityManager->flush();

        // Act: Visit projects page and find restore form in deleted section
        $this->loginAsUser($this->client, $user);
        $crawler = $this->client->request('GET', '/en/projects');

        // Find and submit the restore form
        $restoreForm = $crawler->filter('form[action="/en/projects/' . $projectId . '/restore"]')->form();
        $this->client->submit($restoreForm);

        // Assert: Redirects to list
        self::assertResponseRedirects('/en/projects');

        // Assert: Project is no longer deleted
        $this->entityManager->clear();
        $restoredProject = $this->entityManager->find(Project::class, $projectId);
        self::assertNotNull($restoredProject);
        self::assertFalse($restoredProject->isDeleted());
        self::assertNull($restoredProject->getDeletedAt());
    }

    public function testRestoreRequiresSoftDeletedProject(): void
    {
        // Arrange: Create an active (not soft-deleted) project
        $user    = $this->createTestUser('user-' . uniqid() . '@example.com', 'password123');
        $project = $this->createProject('Active Project');

        $projectId = $project->getId();
        self::assertNotNull($projectId);

        // Act: Try to restore an active project
        // Note: Controller checks project state before CSRF validation, so we use a dummy token
        $this->loginAsUser($this->client, $user);
        $this->client->request('POST', '/en/projects/' . $projectId . '/restore', [
            '_csrf_token' => 'dummy-token',
        ]);

        // Assert: Returns 404 (project not in deleted state)
        self::assertResponseStatusCodeSame(404);

        $this->entityManager->clear();
        $unchangedProject = $this->entityManager->find(Project::class, $projectId);
        self::assertNotNull($unchangedProject);
        self::assertFalse($unchangedProject->isDeleted());
        self::assertNull($unchangedProject->getDeletedAt());
    }

    public function testRestoredProjectAppearsInActiveList(): void
    {
        // Arrange
        $user    = $this->createTestUser('user-' . uniqid() . '@example.com', 'password123');
        $project = $this->createProject('Project To Restore');

        $projectId = $project->getId();
        self::assertNotNull($projectId);

        // Soft delete and then restore
        $project->markAsDeleted();
        $this->entityManager->flush();

        $this->loginAsUser($this->client, $user);
        $crawler = $this->client->request('GET', '/en/projects');

        $restoreForm = $crawler->filter('form[action="/en/projects/' . $projectId . '/restore"]')->form();
        $this->client->submit($restoreForm);
        $this->client->followRedirect();

        // Assert: Project appears in active list, not deleted section
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h3', 'Project To Restore');
    }

    private function createTestUser(string $email, string $plainPassword): AccountCore
    {
        // Use proper registration to trigger organization creation via event
        $user = $this->accountDomainService->register($email, $plainPassword);

        // Get the organization that was created via the event
        $userId = $user->getId();
        self::assertNotNull($userId);

        $this->testOrganizationId = $this->accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($userId);
        self::assertNotNull($this->testOrganizationId, 'User should have an organization after registration');

        return $user;
    }

    private function createProject(string $name): Project
    {
        self::assertNotNull($this->testOrganizationId, 'Must create user before creating project');

        $project = new Project(
            $this->testOrganizationId,
            $name,
            'https://github.com/test/repo.git',
            'ghp_testtoken123',
            LlmModelProvider::OpenAI,
            'sk-test-key-123'
        );
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    private function createWorkspace(string $projectId): Workspace
    {
        $workspace = new Workspace($projectId);
        $workspace->setStatus(WorkspaceStatus::AVAILABLE_FOR_SETUP);
        $this->entityManager->persist($workspace);
        $this->entityManager->flush();

        return $workspace;
    }
}
