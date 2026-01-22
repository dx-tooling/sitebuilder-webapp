<?php

declare(strict_types=1);

namespace Tests\Unit\ProjectMgmt;

use App\ProjectMgmt\Domain\Entity\Project;
use App\ProjectMgmt\Domain\Service\ProjectService;
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
            'github-token-123'
        );

        self::assertSame('My Project', $project->getName());
        self::assertSame('https://github.com/org/repo.git', $project->getGitUrl());
        self::assertSame('github-token-123', $project->getGithubToken());
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
            'new-token'
        );

        self::assertSame('New Name', $project->getName());
        self::assertSame('https://new.git', $project->getGitUrl());
        self::assertSame('new-token', $project->getGithubToken());
    }

    public function testUpdatePreservesCreatedAtTimestamp(): void
    {
        $project           = $this->createProject('proj-1', 'Old Name', 'https://old.git', 'old-token');
        $originalCreatedAt = $project->getCreatedAt();

        $entityManager = $this->createMock(EntityManagerInterface::class);

        $service = new ProjectService($entityManager);
        $service->update($project, 'New Name', 'https://new.git', 'new-token');

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
        $project = new Project($name, $gitUrl, $githubToken);

        // Use reflection to set the ID since it's normally set by Doctrine
        $reflection = new ReflectionClass($project);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($project, $id);

        return $project;
    }
}
