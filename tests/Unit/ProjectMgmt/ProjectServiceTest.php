<?php

declare(strict_types=1);

namespace App\Tests\Unit\ProjectMgmt;

use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
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
            'org-123',
            'My Project',
            'https://github.com/org/repo.git',
            'github-token-123',
            LlmModelProvider::OpenAI,
            'sk-test-key-123'
        );

        self::assertSame('org-123', $project->getOrganizationId());
        self::assertSame('My Project', $project->getName());
        self::assertSame('https://github.com/org/repo.git', $project->getGitUrl());
        self::assertSame('github-token-123', $project->getGithubToken());
        self::assertSame('sk-test-key-123', $project->getContentEditingLlmModelProviderApiKey());
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
            'org-123',
            'My Project',
            'https://github.com/org/repo.git',
            'token',
            LlmModelProvider::OpenAI,
            'sk-key',
            \App\ProjectMgmt\Facade\Enum\ProjectType::DEFAULT,
            'node:22-slim',
            null,
            null,
            null,
            $manifestUrls
        );

        self::assertSame($manifestUrls, $project->getRemoteContentAssetsManifestUrls());
    }

    public function testCloneProjectCopiesConfigurationWithoutCopyingIdentityFields(): void
    {
        $sourceProject = $this->createProject(
            'source-project-id',
            'Source Project',
            'https://github.com/org/source.git',
            'gh-source-token'
        );
        $sourceProject->setAgentImage('python:3.12-slim');
        $sourceProject->setAgentBackgroundInstructions('Background instructions');
        $sourceProject->setAgentStepInstructions('Step instructions');
        $sourceProject->setAgentOutputInstructions('Output instructions');
        $sourceProject->setRemoteContentAssetsManifestUrls(['https://cdn.example.com/manifest.json']);
        $sourceProject->setS3BucketName('bucket-name');
        $sourceProject->setS3Region('eu-central-1');
        $sourceProject->setS3AccessKeyId('AKIAEXAMPLE');
        $sourceProject->setS3SecretAccessKey('secret-example');
        $sourceProject->setS3IamRoleArn('arn:aws:iam::123456789012:role/SiteBuilder');
        $sourceProject->setS3KeyPrefix('uploads/project');
        $sourceProject->setKeysVisible(false);
        $sourceProject->setPhotoBuilderLlmModelProvider(LlmModelProvider::OpenAI);
        $sourceProject->setPhotoBuilderLlmModelProviderApiKey('photo-builder-key');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $service       = new ProjectService($entityManager);
        $clonedProject = $service->cloneProject($sourceProject, 'Cloned Project');

        self::assertNotSame($sourceProject, $clonedProject);
        self::assertSame('org-123', $clonedProject->getOrganizationId());
        self::assertSame('Cloned Project', $clonedProject->getName());
        self::assertSame($sourceProject->getGitUrl(), $clonedProject->getGitUrl());
        self::assertSame($sourceProject->getGithubToken(), $clonedProject->getGithubToken());
        self::assertSame($sourceProject->getContentEditingLlmModelProvider(), $clonedProject->getContentEditingLlmModelProvider());
        self::assertSame($sourceProject->getContentEditingLlmModelProviderApiKey(), $clonedProject->getContentEditingLlmModelProviderApiKey());
        self::assertSame($sourceProject->getAgentImage(), $clonedProject->getAgentImage());
        self::assertSame($sourceProject->getAgentBackgroundInstructions(), $clonedProject->getAgentBackgroundInstructions());
        self::assertSame($sourceProject->getAgentStepInstructions(), $clonedProject->getAgentStepInstructions());
        self::assertSame($sourceProject->getAgentOutputInstructions(), $clonedProject->getAgentOutputInstructions());
        self::assertSame($sourceProject->getRemoteContentAssetsManifestUrls(), $clonedProject->getRemoteContentAssetsManifestUrls());
        self::assertSame($sourceProject->getS3BucketName(), $clonedProject->getS3BucketName());
        self::assertSame($sourceProject->getS3Region(), $clonedProject->getS3Region());
        self::assertSame($sourceProject->getS3AccessKeyId(), $clonedProject->getS3AccessKeyId());
        self::assertSame($sourceProject->getS3SecretAccessKey(), $clonedProject->getS3SecretAccessKey());
        self::assertSame($sourceProject->getS3IamRoleArn(), $clonedProject->getS3IamRoleArn());
        self::assertSame($sourceProject->getS3KeyPrefix(), $clonedProject->getS3KeyPrefix());
        self::assertSame($sourceProject->isKeysVisible(), $clonedProject->isKeysVisible());
        self::assertSame($sourceProject->getPhotoBuilderLlmModelProvider(), $clonedProject->getPhotoBuilderLlmModelProvider());
        self::assertSame($sourceProject->getPhotoBuilderLlmModelProviderApiKey(), $clonedProject->getPhotoBuilderLlmModelProviderApiKey());
        self::assertNull($clonedProject->getId());
        self::assertFalse($clonedProject->isDeleted());
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
        self::assertSame('sk-new-key', $project->getContentEditingLlmModelProviderApiKey());
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
        $project = new Project('org-123', $name, $gitUrl, $githubToken, LlmModelProvider::OpenAI, 'sk-test-key');

        // Use reflection to set the ID since it's normally set by Doctrine
        $reflection = new ReflectionClass($project);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($project, $id);

        return $project;
    }
}
