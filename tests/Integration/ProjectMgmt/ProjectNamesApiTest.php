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

/**
 * Integration tests for the auth-free project names API endpoint.
 */
final class ProjectNamesApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container    = static::getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager       = $container->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;
    }

    public function testReturnsJsonArrayOfProjectNames(): void
    {
        $organizationId = $this->createOrganizationWithUser();

        $this->createProject($organizationId, 'Beta Project');
        $this->createProject($organizationId, 'Alpha Project');

        $this->client->request('GET', '/api/project-names');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');

        /** @var list<string> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertIsArray($data);
        self::assertContains('Alpha Project', $data);
        self::assertContains('Beta Project', $data);

        $alphaIndex = array_search('Alpha Project', $data, true);
        $betaIndex  = array_search('Beta Project', $data, true);
        self::assertLessThan($betaIndex, $alphaIndex, 'Projects should be sorted alphabetically');
    }

    public function testExcludesSoftDeletedProjects(): void
    {
        $organizationId = $this->createOrganizationWithUser();

        $this->createProject($organizationId, 'Active Project');
        $deleted = $this->createProject($organizationId, 'Deleted Project');
        $deleted->markAsDeleted();
        $this->entityManager->flush();

        $this->client->request('GET', '/api/project-names');

        self::assertResponseIsSuccessful();

        /** @var list<string> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertContains('Active Project', $data);
        self::assertNotContains('Deleted Project', $data);
    }

    public function testAccessibleWithoutAuthentication(): void
    {
        $this->client->request('GET', '/api/project-names');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testReturnsEmptyArrayWhenNoProjects(): void
    {
        $this->client->request('GET', '/api/project-names');

        self::assertResponseIsSuccessful();

        /** @var list<string> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertIsArray($data);
        self::assertEmpty($data);
    }

    private function createOrganizationWithUser(): string
    {
        $container = static::getContainer();

        /** @var AccountDomainService $accountDomainService */
        $accountDomainService = $container->get(AccountDomainService::class);
        $user                 = $accountDomainService->register('api-test-' . uniqid() . '@example.com', 'password123');

        $userId = $user->getId();
        self::assertNotNull($userId);

        /** @var AccountFacadeInterface $accountFacade */
        $accountFacade  = $container->get(AccountFacadeInterface::class);
        $organizationId = $accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($userId);
        self::assertNotNull($organizationId);

        return $organizationId;
    }

    private function createProject(string $organizationId, string $name): Project
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
}
