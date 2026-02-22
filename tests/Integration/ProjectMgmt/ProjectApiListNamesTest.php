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

    public function testReturnsJsonArrayOfStrings(): void
    {
        $this->client->request('GET', '/en/api/projects');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertIsList($data);

        foreach ($data as $item) {
            self::assertIsString($item);
        }
    }

    public function testReturnsProjectNamesSortedAlphabetically(): void
    {
        $uniqueSuffix = uniqid();
        $this->createOrganization();
        $this->createProject('Zeta Sorted Test ' . $uniqueSuffix);
        $this->createProject('Alpha Sorted Test ' . $uniqueSuffix);
        $this->createProject('Mu Sorted Test ' . $uniqueSuffix);

        $this->client->request('GET', '/en/api/projects');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');

        /** @var list<string> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        self::assertContains('Alpha Sorted Test ' . $uniqueSuffix, $data);
        self::assertContains('Mu Sorted Test ' . $uniqueSuffix, $data);
        self::assertContains('Zeta Sorted Test ' . $uniqueSuffix, $data);

        $sorted = $data;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $data);
    }

    public function testExcludesSoftDeletedProjects(): void
    {
        $uniqueSuffix = uniqid();
        $this->createOrganization();
        $this->createProject('Active Test Project ' . $uniqueSuffix);
        $deletedProject = $this->createProject('Deleted Test Project ' . $uniqueSuffix);

        $deletedProject->markAsDeleted();
        $this->entityManager->flush();

        $this->client->request('GET', '/en/api/projects');

        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertContains('Active Test Project ' . $uniqueSuffix, $data);
        self::assertNotContains('Deleted Test Project ' . $uniqueSuffix, $data);
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
