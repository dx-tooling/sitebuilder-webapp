<?php

declare(strict_types=1);

namespace App\Tests\Integration\ProjectMgmt;

use App\Account\Domain\Service\AccountDomainService;
use App\Account\Facade\AccountFacadeInterface;
use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\ProjectMgmt\Domain\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProjectApiListNamesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?string $testOrganizationId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container    = static::getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager       = $container->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;
    }

    public function testReturnsEmptyArrayWhenNoProjectsExist(): void
    {
        $this->client->request('GET', '/en/api/projects');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame([], $data);
    }

    public function testReturnsProjectNamesSortedAlphabetically(): void
    {
        $this->createOrganization();
        $this->createProject('Zeta Project');
        $this->createProject('Alpha Project');
        $this->createProject('Mu Project');

        $this->client->request('GET', '/en/api/projects');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['Alpha Project', 'Mu Project', 'Zeta Project'], $data);
    }

    public function testExcludesSoftDeletedProjects(): void
    {
        $this->createOrganization();
        $activeProject  = $this->createProject('Active Project');
        $deletedProject = $this->createProject('Deleted Project');

        $deletedProject->markAsDeleted();
        $this->entityManager->flush();

        $this->client->request('GET', '/en/api/projects');

        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['Active Project'], $data);
    }

    public function testAccessibleWithoutAuthentication(): void
    {
        $this->client->request('GET', '/en/api/projects');

        self::assertResponseIsSuccessful();
        self::assertResponseStatusCodeSame(200);
    }

    private function createOrganization(): void
    {
        /** @var AccountDomainService $accountDomainService */
        $accountDomainService = static::getContainer()->get(AccountDomainService::class);

        /** @var AccountFacadeInterface $accountFacade */
        $accountFacade = static::getContainer()->get(AccountFacadeInterface::class);

        $user   = $accountDomainService->register('api-test-' . uniqid() . '@example.com', 'password123');
        $userId = $user->getId();
        self::assertNotNull($userId);

        $this->testOrganizationId = $accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($userId);
        self::assertNotNull($this->testOrganizationId);
    }

    private function createProject(string $name): Project
    {
        self::assertNotNull($this->testOrganizationId, 'Must call createOrganization() before creating projects');

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
}
