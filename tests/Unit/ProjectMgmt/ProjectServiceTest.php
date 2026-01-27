<?php

declare(strict_types=1);

namespace Tests\Unit\ProjectMgmt;

use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\ProjectMgmt\Domain\Entity\Project;
use App\ProjectMgmt\Domain\Service\ProjectService;
use App\ProjectMgmt\Facade\Enum\ContentEditorBackend;
use App\ProjectMgmt\Facade\Enum\ProjectType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ProjectServiceTest extends TestCase
{
    public function testCreateCreatesProjectWithGivenAttributes(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $service = new ProjectService($entityManager);
        $project = $service->create(
            'My Project',
            'https://github.com/org/repo.git',
            'github-token-123',
            LlmModelProvider::OpenAI,
            'sk-test-key-123'
        );

        self::assertSame('My Project', $project->getName());
        self::assertSame('https://github.com/org/repo.git', $project->getGitUrl());
        self::assertSame('github-token-123', $project->getGithubToken());
        self::assertSame('sk-test-key-123', $project->getLlmApiKey());
        self::assertSame([], $project->getRemoteContentAssetsManifestUrls());
    }

    public function testCreateStoresRemoteContentAssetsManifestUrlsWhenProvided(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $service      = new ProjectService($entityManager);
        $manifestUrls = ['https://cdn.example.com/manifest.json'];
        $project      = $service->create(
            'My Project',
            'https://github.com/org/repo.git',
            'token',
            LlmModelProvider::OpenAI,
            'sk-key',
            ProjectType::DEFAULT,
            ContentEditorBackend::Llm,
            'node:22-slim',
            null,
            null,
            null,
            $manifestUrls
        );

        self::assertSame($manifestUrls, $project->getRemoteContentAssetsManifestUrls());
    }

    public function testUpdateUpdatesAllProjectAttributes(): void
    {
        $project = $this->createProject('proj-1', 'Old Name', 'https://old.git', 'old-token');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $service = new ProjectService($entityManager);
        $service->update(
            $project,
            'New Name',
            'https://new.git',
            'new-token',
            LlmModelProvider::OpenAI,
            'sk-new-key'
        );

        self::assertSame('New Name', $project->getName());
        self::assertSame('https://new.git', $project->getGitUrl());
        self::assertSame('new-token', $project->getGithubToken());
        self::assertSame('sk-new-key', $project->getLlmApiKey());
    }

    public function testUpdatePreservesCreatedAtTimestamp(): void
    {
        $project           = $this->createProject('proj-1', 'Old Name', 'https://old.git', 'old-token');
        $originalCreatedAt = $project->getCreatedAt();

        $entityManager = $this->createMock(EntityManagerInterface::class);

        $service = new ProjectService($entityManager);
        $service->update($project, 'New Name', 'https://new.git', 'new-token', LlmModelProvider::OpenAI, 'sk-key');

        self::assertSame($originalCreatedAt, $project->getCreatedAt());
    }

    public function testFindByIdReturnsProjectWhenFound(): void
    {
        $project = $this->createProject('proj-1', 'Test', 'https://test.git', 'token');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())
            ->method('find')
            ->with(Project::class, 'proj-1')
            ->willReturn($project);

        $service = new ProjectService($entityManager);
        $result  = $service->findById('proj-1');

        self::assertSame($project, $result);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())
            ->method('find')
            ->with(Project::class, 'non-existent')
            ->willReturn(null);

        $service = new ProjectService($entityManager);
        $result  = $service->findById('non-existent');

        self::assertNull($result);
    }

    /**
     * Helper to create a Project with reflection to set the ID.
     */
    private function createProject(string $id, string $name, string $gitUrl, string $githubToken): Project
    {
        $project = new Project($name, $gitUrl, $githubToken, LlmModelProvider::OpenAI, 'sk-test-key');

        // Use reflection to set the ID since it's normally set by Doctrine
        $reflection = new ReflectionClass($project);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($project, $id);

        return $project;
    }
}
