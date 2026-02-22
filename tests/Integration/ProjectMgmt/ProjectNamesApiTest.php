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
        self::assertIsArray($data);
        self::assertArrayHasKey('projectNames', $data);
        self::assertSame([], $data['projectNames']);
    }

    public function testReturnsOnlyNonDeletedProjectNames(): void
    {
        $this->createTestOrganization();

        $this->createProject('Active Alpha');
        $this->createProject('Active Beta');
        $deletedProject = $this->createProject('Deleted Gamma');

        $deletedProject->markAsDeleted();
        $this->entityManager->flush();

        $this->client->request('GET', '/api/project-names');

        self::assertResponseIsSuccessful();

        $data = $this->decodeResponse();
        self::assertSame(['Active Alpha', 'Active Beta'], $data['projectNames']);
    }

    public function testReturnsNamesInAlphabeticalOrder(): void
    {
        $this->createTestOrganization();

        $this->createProject('Zulu');
        $this->createProject('Alpha');
        $this->createProject('Mike');

        $this->client->request('GET', '/api/project-names');

        self::assertResponseIsSuccessful();

        $data = $this->decodeResponse();
        self::assertSame(['Alpha', 'Mike', 'Zulu'], $data['projectNames']);
    }

    public function testAccessibleWithoutAuthentication(): void
    {
        $this->client->request('GET', '/api/project-names');

        self::assertResponseIsSuccessful();
        self::assertResponseStatusCodeSame(200);
    }

    private function createTestOrganization(): void
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

    /**
     * @return array{projectNames: list<string>}
     */
    private function decodeResponse(): array
    {
        $content = (string) $this->client->getResponse()->getContent();
        $decoded = json_decode($content, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('projectNames', $decoded);

        /** @var array{projectNames: list<string>} $decoded */
        return $decoded;
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
