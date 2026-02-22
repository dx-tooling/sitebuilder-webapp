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

final class ProjectNamesApiTest extends WebTestCase
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

    public function testReturnsEmptyListWhenNoProjectsExist(): void
    {
        $this->client->request('GET', '/api/project-names');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['projects' => []], $data);
    }

    public function testReturnsProjectNames(): void
    {
        $this->createOrganization();
        $this->createProject('Alpha Project');
        $this->createProject('Beta Project');

        $this->client->request('GET', '/api/project-names');

        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['projects' => ['Alpha Project', 'Beta Project']], $data);
    }

    public function testExcludesDeletedProjects(): void
    {
        $this->createOrganization();
        $this->createProject('Active Project');
        $deleted = $this->createProject('Deleted Project');
        $deleted->markAsDeleted();
        $this->entityManager->flush();

        $this->client->request('GET', '/api/project-names');

        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['projects' => ['Active Project']], $data);
    }

    public function testEndpointIsAccessibleWithoutAuthentication(): void
    {
        $this->client->request('GET', '/api/project-names');

        self::assertResponseIsSuccessful();
        self::assertResponseStatusCodeSame(200);
    }

    private function createOrganization(): void
    {
        $container = static::getContainer();

        /** @var AccountDomainService $accountDomainService */
        $accountDomainService = $container->get(AccountDomainService::class);

        /** @var AccountFacadeInterface $accountFacade */
        $accountFacade = $container->get(AccountFacadeInterface::class);

        $user   = $accountDomainService->register('api-test-' . uniqid() . '@example.com', 'password123');
        $userId = $user->getId();
        self::assertNotNull($userId);

        $this->testOrganizationId = $accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($userId);
        self::assertNotNull($this->testOrganizationId);
    }

    private function createProject(string $name): Project
    {
        self::assertNotNull($this->testOrganizationId, 'Must create organization before creating project');

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
