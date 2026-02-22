<?php

declare(strict_types=1);

namespace App\Tests\Unit\ProjectMgmt;

use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\ProjectMgmt\Api\Controller\ProjectNameApiController;
use App\ProjectMgmt\Facade\Dto\ProjectInfoDto;
use App\ProjectMgmt\Facade\Enum\ProjectType;
use App\ProjectMgmt\Facade\ProjectMgmtFacadeInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;

final class ProjectNameApiControllerTest extends TestCase
{
    public function testReturnsProjectNamesAsJson(): void
    {
        $facade = $this->createMock(ProjectMgmtFacadeInterface::class);
        $facade->method('getProjectInfos')->willReturn([
            $this->createProjectInfoDto('id-1', 'Project Alpha'),
            $this->createProjectInfoDto('id-2', 'Project Beta'),
        ]);

        $controller = new ProjectNameApiController($facade);
        $controller->setContainer(new Container());

        $response = $controller->__invoke();

        self::assertSame(200, $response->getStatusCode());

        /** @var list<array{id: string, name: string}> $data */
        $data = json_decode((string) $response->getContent(), true);
        self::assertCount(2, $data);
        self::assertSame(['id' => 'id-1', 'name' => 'Project Alpha'], $data[0]);
        self::assertSame(['id' => 'id-2', 'name' => 'Project Beta'], $data[1]);
    }

    public function testReturnsEmptyArrayWhenNoProjects(): void
    {
        $facade = $this->createMock(ProjectMgmtFacadeInterface::class);
        $facade->method('getProjectInfos')->willReturn([]);

        $controller = new ProjectNameApiController($facade);
        $controller->setContainer(new Container());

        $response = $controller->__invoke();

        self::assertSame(200, $response->getStatusCode());

        /** @var list<mixed> $data */
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame([], $data);
    }

    public function testResponseContainsOnlyIdAndNameFields(): void
    {
        $facade = $this->createMock(ProjectMgmtFacadeInterface::class);
        $facade->method('getProjectInfos')->willReturn([
            new ProjectInfoDto(
                'id-secret',
                'Sensitive Project',
                'https://github.com/org/secret.git',
                'ghp_supersecret',
                ProjectType::DEFAULT,
                'https://github.com/org/secret',
                'agent:latest',
                LlmModelProvider::OpenAI,
                'sk-supersecretkey',
                'background',
                'step',
                'output',
                [],
                'my-bucket',
                'us-east-1',
                'AKIAIOSFODNN7',
                's3-secret-key',
            ),
        ]);

        $controller = new ProjectNameApiController($facade);
        $controller->setContainer(new Container());

        $response = $controller->__invoke();

        /** @var list<array<string, string>> $data */
        $data = json_decode((string) $response->getContent(), true);
        self::assertCount(1, $data);
        self::assertSame(['id', 'name'], array_keys($data[0]));
        self::assertSame('id-secret', $data[0]['id']);
        self::assertSame('Sensitive Project', $data[0]['name']);
    }

    private function createProjectInfoDto(string $id, string $name): ProjectInfoDto
    {
        return new ProjectInfoDto(
            $id,
            $name,
            'https://github.com/org/repo.git',
            'ghp_token',
            ProjectType::DEFAULT,
            'https://github.com/org/repo',
            'agent:latest',
            LlmModelProvider::OpenAI,
            'sk-key',
            'background',
            'step',
            'output',
        );
    }
}
