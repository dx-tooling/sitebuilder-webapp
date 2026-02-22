<?php

declare(strict_types=1);

namespace App\Tests\Application\ProjectMgmt;

use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\ProjectMgmt\Domain\Service\ProjectService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ContentProjectNamesApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private ProjectService $projectService;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container    = static::getContainer();

        /** @var ProjectService $projectService */
        $projectService       = $container->get(ProjectService::class);
        $this->projectService = $projectService;
    }

    public function testEndpointReturnsJsonArrayWithoutAuthentication(): void
    {
        $this->client->request('GET', '/api/content-projects');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');

        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);

        $decoded = json_decode($content, true);
        self::assertIsArray($decoded);
    }

    public function testEndpointReturnsCreatedProjectName(): void
    {
        $projectName = 'API Test Project ' . uniqid();

        $this->projectService->create(
            '00000000-0000-0000-0000-000000000000',
            $projectName,
            'https://github.com/test/repo.git',
            'fake-token',
            LlmModelProvider::OpenAI,
            'fake-api-key',
        );

        $this->client->request('GET', '/api/content-projects');

        self::assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);

        $decoded = json_decode($content, true);
        self::assertIsArray($decoded);
        self::assertContains($projectName, $decoded);
    }

    public function testEndpointExcludesDeletedProjects(): void
    {
        $projectName = 'Deleted API Test Project ' . uniqid();

        $project = $this->projectService->create(
            '00000000-0000-0000-0000-000000000000',
            $projectName,
            'https://github.com/test/repo.git',
            'fake-token',
            LlmModelProvider::OpenAI,
            'fake-api-key',
        );

        $this->projectService->delete($project);

        $this->client->request('GET', '/api/content-projects');

        self::assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);

        $decoded = json_decode($content, true);
        self::assertIsArray($decoded);
        self::assertNotContains($projectName, $decoded);
    }
}
