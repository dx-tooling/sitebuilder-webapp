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

    public function testReturnsJsonArrayOfProjectNames(): void
    {
        $this->createProject('Alpha Project');
        $this->createProject('Beta Project');
        $this->createProject('Gamma Project');

        $this->client->request('GET', '/api/project-names');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertContains('Alpha Project', $data);
        self::assertContains('Beta Project', $data);
        self::assertContains('Gamma Project', $data);
    }

    public function testExcludesSoftDeletedProjects(): void
    {
        $this->createProject('Active Project');
        $deletedProject = $this->createProject('Deleted Project');
        $deletedProject->markAsDeleted();
        $this->entityManager->flush();

        $this->client->request('GET', '/api/project-names');

        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertContains('Active Project', $data);
        self::assertNotContains('Deleted Project', $data);
    }

    public function testReturnsNamesInAlphabeticalOrder(): void
    {
        $this->createProject('Zebra');
        $this->createProject('Apple');
        $this->createProject('Mango');

        $this->client->request('GET', '/api/project-names');

        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        $filteredData = array_values(array_filter($data, fn (string $name): bool => in_array($name, ['Apple', 'Mango', 'Zebra'], true)));
        self::assertSame(['Apple', 'Mango', 'Zebra'], $filteredData);
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

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
    }

    private function createProject(string $name): Project
    {
        self::assertNotNull($this->testOrganizationId);

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
