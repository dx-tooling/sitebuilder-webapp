<?php

declare(strict_types=1);

namespace App\ProjectMgmt\Domain\Service;

use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\ProjectMgmt\Domain\Entity\Project;
use App\ProjectMgmt\Facade\Enum\ProjectType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Internal domain service for project CRUD operations.
 * Used by ProjectController within the same vertical.
 */
final class ProjectService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<string>|null $remoteContentAssetsManifestUrls
     */
    public function create(
        string            $organizationId,
        string            $name,
        string            $gitUrl,
        string            $githubToken,
        LlmModelProvider  $contentEditingLlmModelProvider,
        string            $contentEditingLlmModelProviderApiKey,
        ProjectType       $projectType = ProjectType::DEFAULT,
        string            $agentImage = Project::DEFAULT_AGENT_IMAGE,
        ?string           $agentBackgroundInstructions = null,
        ?string           $agentStepInstructions = null,
        ?string           $agentOutputInstructions = null,
        ?array            $remoteContentAssetsManifestUrls = null,
        ?string           $s3BucketName = null,
        ?string           $s3Region = null,
        ?string           $s3AccessKeyId = null,
        ?string           $s3SecretAccessKey = null,
        ?string           $s3IamRoleArn = null,
        ?string           $s3KeyPrefix = null,
        bool              $keysVisible = true,
        ?LlmModelProvider $photoBuilderLlmModelProvider = null,
        ?string           $photoBuilderLlmModelProviderApiKey = null,
    ): Project {
        $project = new Project(
            $organizationId,
            $name,
            $gitUrl,
            $githubToken,
            $contentEditingLlmModelProvider,
            $contentEditingLlmModelProviderApiKey,
            $projectType,
            $agentImage,
            $agentBackgroundInstructions,
            $agentStepInstructions,
            $agentOutputInstructions,
            $remoteContentAssetsManifestUrls
        );

        $project->setKeysVisible($keysVisible);
        $project->setPhotoBuilderLlmModelProvider($photoBuilderLlmModelProvider);
        $project->setPhotoBuilderLlmModelProviderApiKey($photoBuilderLlmModelProviderApiKey);

        // Set S3 configuration if provided
        $project->setS3BucketName($s3BucketName);
        $project->setS3Region($s3Region);
        $project->setS3AccessKeyId($s3AccessKeyId);
        $project->setS3SecretAccessKey($s3SecretAccessKey);
        $project->setS3IamRoleArn($s3IamRoleArn);
        $project->setS3KeyPrefix($s3KeyPrefix);

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    /**
     * @param list<string>|null $remoteContentAssetsManifestUrls
     */
    public function update(
        Project           $project,
        string            $name,
        string            $gitUrl,
        string            $githubToken,
        LlmModelProvider  $contentEditingLlmModelProvider,
        string            $contentEditingLlmModelProviderApiKey,
        ProjectType       $projectType = ProjectType::DEFAULT,
        string            $agentImage = Project::DEFAULT_AGENT_IMAGE,
        ?string           $agentBackgroundInstructions = null,
        ?string           $agentStepInstructions = null,
        ?string           $agentOutputInstructions = null,
        ?array            $remoteContentAssetsManifestUrls = null,
        ?string           $s3BucketName = null,
        ?string           $s3Region = null,
        ?string           $s3AccessKeyId = null,
        ?string           $s3SecretAccessKey = null,
        ?string           $s3IamRoleArn = null,
        ?string           $s3KeyPrefix = null,
        ?LlmModelProvider $photoBuilderLlmModelProvider = null,
        ?string           $photoBuilderLlmModelProviderApiKey = null,
    ): void {
        $project->setName($name);
        $project->setGitUrl($gitUrl);
        $project->setGithubToken($githubToken);
        $project->setContentEditingLlmModelProvider($contentEditingLlmModelProvider);
        $project->setContentEditingLlmModelProviderApiKey($contentEditingLlmModelProviderApiKey);
        $project->setProjectType($projectType);
        $project->setAgentImage($agentImage);

        if ($agentBackgroundInstructions !== null) {
            $project->setAgentBackgroundInstructions($agentBackgroundInstructions);
        }
        if ($agentStepInstructions !== null) {
            $project->setAgentStepInstructions($agentStepInstructions);
        }
        if ($agentOutputInstructions !== null) {
            $project->setAgentOutputInstructions($agentOutputInstructions);
        }
        if ($remoteContentAssetsManifestUrls !== null) {
            $project->setRemoteContentAssetsManifestUrls($remoteContentAssetsManifestUrls);
        }

        // PhotoBuilder LLM fields (null = use content editing settings)
        $project->setPhotoBuilderLlmModelProvider($photoBuilderLlmModelProvider);
        $project->setPhotoBuilderLlmModelProviderApiKey($photoBuilderLlmModelProviderApiKey);

        // S3 fields are always updated (can be cleared by passing null)
        $project->setS3BucketName($s3BucketName);
        $project->setS3Region($s3Region);
        $project->setS3AccessKeyId($s3AccessKeyId);
        $project->setS3SecretAccessKey($s3SecretAccessKey);
        $project->setS3IamRoleArn($s3IamRoleArn);
        $project->setS3KeyPrefix($s3KeyPrefix);

        $this->entityManager->flush();
    }

    public function findById(string $id): ?Project
    {
        return $this->entityManager->find(Project::class, $id);
    }

    /**
     * Soft delete a project by marking it as deleted.
     */
    public function delete(Project $project): void
    {
        $project->markAsDeleted();
        $this->entityManager->flush();
    }

    /**
     * Permanently delete a project from the database.
     */
    public function permanentlyDelete(Project $project): void
    {
        $this->entityManager->remove($project);
        $this->entityManager->flush();
    }

    /**
     * Restore a soft-deleted project.
     */
    public function restore(Project $project): void
    {
        $project->restore();
        $this->entityManager->flush();
    }

    /**
     * Find all non-deleted projects for an organization.
     *
     * @return list<Project>
     */
    public function findAllForOrganization(string $organizationId): array
    {
        /** @var list<Project> $projects */
        $projects = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Project::class, 'p')
            ->where('p.deletedAt IS NULL')
            ->andWhere('p.organizationId = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $projects;
    }

    /**
     * Find all soft-deleted projects for an organization.
     *
     * @return list<Project>
     */
    public function findAllDeletedForOrganization(string $organizationId): array
    {
        /** @var list<Project> $projects */
        $projects = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Project::class, 'p')
            ->where('p.deletedAt IS NOT NULL')
            ->andWhere('p.organizationId = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->orderBy('p.deletedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $projects;
    }
}
