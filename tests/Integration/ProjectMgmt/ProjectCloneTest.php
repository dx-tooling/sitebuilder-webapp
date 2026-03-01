<?php

declare(strict_types=1);

namespace App\Tests\Integration\ProjectMgmt;

use App\Account\Domain\Entity\AccountCore;
use App\Account\Domain\Service\AccountDomainService;
use App\Account\Facade\AccountFacadeInterface;
use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\ProjectMgmt\Domain\Entity\Project;
use App\ProjectMgmt\Facade\Enum\ProjectType;
use App\Tests\Support\SecurityUserLoginTrait;
use App\WorkspaceMgmt\Domain\Entity\Workspace;
use App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration tests for cloning projects.
 */
final class ProjectCloneTest extends WebTestCase
{
    use SecurityUserLoginTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private AccountDomainService $accountDomainService;
    private AccountFacadeInterface $accountFacade;

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

    public function testProjectListShowsCloneAction(): void
    {
        $user           = $this->createTestUser('clone-list-' . uniqid() . '@example.com', 'password123');
        $organizationId = $this->getOrganizationIdForUser($user);
        $project        = $this->createBasicProject($organizationId, 'Project To Clone');

        $projectId = $project->getId();
        self::assertNotNull($projectId);

        $this->loginAsUser($this->client, $user);
        $crawler = $this->client->request('GET', '/en/projects');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $crawler->filter('a[href="/en/projects/' . $projectId . '/clone"][data-test-class="project-list-clone-link"]')->count()
        );
    }

    public function testCloneFormShowsSuggestedNameForSourceProject(): void
    {
        $user           = $this->createTestUser('clone-form-' . uniqid() . '@example.com', 'password123');
        $organizationId = $this->getOrganizationIdForUser($user);
        $project        = $this->createBasicProject($organizationId, 'Source Website');

        $projectId = $project->getId();
        self::assertNotNull($projectId);

        $this->loginAsUser($this->client, $user);
        $crawler = $this->client->request('GET', '/en/projects/' . $projectId . '/clone');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-test-id="project-clone-form"]');
        self::assertSame('Copy of Source Website', $crawler->filter('[data-test-id="project-clone-name"]')->attr('value'));
    }

    public function testCloneCopiesProjectSettingsAndDoesNotCopyWorkspace(): void
    {
        $user           = $this->createTestUser('clone-happy-' . uniqid() . '@example.com', 'password123');
        $organizationId = $this->getOrganizationIdForUser($user);
        $sourceProject  = $this->createConfiguredProject($organizationId, 'Configured Source Project');

        $sourceProjectId = $sourceProject->getId();
        self::assertNotNull($sourceProjectId);

        $sourceWorkspace = new Workspace($sourceProjectId);
        $sourceWorkspace->setStatus(WorkspaceStatus::IN_REVIEW);
        $sourceWorkspace->setBranchName('feature/original-work');
        $sourceWorkspace->setPullRequestUrl('https://github.com/org/repo/pull/123');
        $this->entityManager->persist($sourceWorkspace);
        $this->entityManager->flush();

        $this->loginAsUser($this->client, $user);
        $crawler = $this->client->request('GET', '/en/projects/' . $sourceProjectId . '/clone');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('[data-test-id="project-clone-form"]')->form([
            'name' => 'Cloned Project',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/en/projects');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'cloned successfully');

        $this->entityManager->clear();

        /** @var Project|null $clonedProject */
        $clonedProject = $this->entityManager->getRepository(Project::class)->findOneBy([
            'organizationId' => $organizationId,
            'name'           => 'Cloned Project',
        ]);

        self::assertNotNull($clonedProject);
        self::assertNotSame($sourceProjectId, $clonedProject->getId());
        self::assertSame($organizationId, $clonedProject->getOrganizationId());
        self::assertSame($sourceProject->getGitUrl(), $clonedProject->getGitUrl());
        self::assertSame($sourceProject->getGithubToken(), $clonedProject->getGithubToken());
        self::assertSame($sourceProject->getContentEditingLlmModelProvider(), $clonedProject->getContentEditingLlmModelProvider());
        self::assertSame($sourceProject->getContentEditingLlmModelProviderApiKey(), $clonedProject->getContentEditingLlmModelProviderApiKey());
        self::assertSame($sourceProject->getAgentImage(), $clonedProject->getAgentImage());
        self::assertSame($sourceProject->getAgentBackgroundInstructions(), $clonedProject->getAgentBackgroundInstructions());
        self::assertSame($sourceProject->getAgentStepInstructions(), $clonedProject->getAgentStepInstructions());
        self::assertSame($sourceProject->getAgentOutputInstructions(), $clonedProject->getAgentOutputInstructions());
        self::assertSame($sourceProject->getRemoteContentAssetsManifestUrls(), $clonedProject->getRemoteContentAssetsManifestUrls());
        self::assertSame($sourceProject->getS3BucketName(), $clonedProject->getS3BucketName());
        self::assertSame($sourceProject->getS3Region(), $clonedProject->getS3Region());
        self::assertSame($sourceProject->getS3AccessKeyId(), $clonedProject->getS3AccessKeyId());
        self::assertSame($sourceProject->getS3SecretAccessKey(), $clonedProject->getS3SecretAccessKey());
        self::assertSame($sourceProject->getS3IamRoleArn(), $clonedProject->getS3IamRoleArn());
        self::assertSame($sourceProject->getS3KeyPrefix(), $clonedProject->getS3KeyPrefix());
        self::assertSame($sourceProject->isKeysVisible(), $clonedProject->isKeysVisible());
        self::assertSame($sourceProject->getPhotoBuilderLlmModelProvider(), $clonedProject->getPhotoBuilderLlmModelProvider());
        self::assertSame($sourceProject->getPhotoBuilderLlmModelProviderApiKey(), $clonedProject->getPhotoBuilderLlmModelProviderApiKey());
        self::assertFalse($clonedProject->isDeleted());

        $clonedProjectId = $clonedProject->getId();
        self::assertNotNull($clonedProjectId);
        self::assertNull(
            $this->entityManager->getRepository(Workspace::class)->findOneBy(['projectId' => $clonedProjectId])
        );
    }

    public function testCloneReturnsNotFoundForProjectFromAnotherOrganization(): void
    {
        $ownerUser           = $this->createTestUser('clone-owner-' . uniqid() . '@example.com', 'password123');
        $ownerOrganizationId = $this->getOrganizationIdForUser($ownerUser);
        $sourceProject       = $this->createBasicProject($ownerOrganizationId, 'Owner Project');

        $otherUser = $this->createTestUser('clone-other-' . uniqid() . '@example.com', 'password123');
        $this->loginAsUser($this->client, $otherUser);

        $projectId = $sourceProject->getId();
        self::assertNotNull($projectId);

        $this->client->request('GET', '/en/projects/' . $projectId . '/clone');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/en/projects/' . $projectId . '/clone', [
            '_csrf_token' => 'dummy-token',
            'name'        => 'Cross Org Clone',
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCloneReturnsNotFoundForDeletedSourceProject(): void
    {
        $user           = $this->createTestUser('clone-deleted-' . uniqid() . '@example.com', 'password123');
        $organizationId = $this->getOrganizationIdForUser($user);
        $sourceProject  = $this->createBasicProject($organizationId, 'Deleted Source');
        $sourceProject->markAsDeleted();
        $this->entityManager->flush();

        $projectId = $sourceProject->getId();
        self::assertNotNull($projectId);

        $this->loginAsUser($this->client, $user);
        $this->client->request('GET', '/en/projects/' . $projectId . '/clone');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/en/projects/' . $projectId . '/clone', [
            '_csrf_token' => 'dummy-token',
            'name'        => 'Clone Attempt',
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCloneWithEmptyNameShowsValidationAndDoesNotCreateProject(): void
    {
        $user           = $this->createTestUser('clone-empty-name-' . uniqid() . '@example.com', 'password123');
        $organizationId = $this->getOrganizationIdForUser($user);
        $sourceProject  = $this->createBasicProject($organizationId, 'Source Name Validation');

        $projectId = $sourceProject->getId();
        self::assertNotNull($projectId);

        $this->loginAsUser($this->client, $user);
        $crawler = $this->client->request('GET', '/en/projects/' . $projectId . '/clone');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('[data-test-id="project-clone-form"]')->form([
            'name' => '   ',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/en/projects/' . $projectId . '/clone');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Please enter a name for the cloned project.');

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(Project::class)->findOneBy([
            'organizationId' => $organizationId,
            'name'           => '   ',
        ]));
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

    private function createBasicProject(string $organizationId, string $name): Project
    {
        $project = new Project(
            $organizationId,
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

    private function createConfiguredProject(string $organizationId, string $name): Project
    {
        $project = new Project(
            $organizationId,
            $name,
            'https://github.com/source/repository.git',
            'ghp_source_token',
            LlmModelProvider::OpenAI,
            'sk-source-api-key',
            ProjectType::DEFAULT,
            'python:3.12-slim',
            'Background instructions',
            'Step instructions',
            'Output instructions',
            ['https://cdn.example.com/manifest.json']
        );
        $project->setS3BucketName('source-bucket');
        $project->setS3Region('eu-central-1');
        $project->setS3AccessKeyId('AKIA_SOURCE');
        $project->setS3SecretAccessKey('source-secret');
        $project->setS3IamRoleArn('arn:aws:iam::123456789012:role/SourceRole');
        $project->setS3KeyPrefix('assets/source');
        $project->setKeysVisible(false);
        $project->setPhotoBuilderLlmModelProvider(LlmModelProvider::OpenAI);
        $project->setPhotoBuilderLlmModelProviderApiKey('sk-photo-builder');

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }
}
