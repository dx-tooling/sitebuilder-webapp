<?php

declare(strict_types=1);

namespace App\ProjectMgmt\Domain\Service;

use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\ProjectMgmt\Domain\Entity\Project;
use App\ProjectMgmt\Facade\Enum\ContentEditorBackend;
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
        string               $name,
        string               $gitUrl,
        string               $githubToken,
        LlmModelProvider     $llmModelProvider,
        string               $llmApiKey,
        ProjectType          $projectType = ProjectType::DEFAULT,
        ContentEditorBackend $contentEditorBackend = ContentEditorBackend::Llm,
        string               $agentImage = Project::DEFAULT_AGENT_IMAGE,
        ?string              $agentBackgroundInstructions = null,
        ?string              $agentStepInstructions = null,
        ?string              $agentOutputInstructions = null,
        ?array               $remoteContentAssetsManifestUrls = null
    ): Project {
        $project = new Project(
            $name,
            $gitUrl,
            $githubToken,
            $llmModelProvider,
            $llmApiKey,
            $projectType,
            $contentEditorBackend,
            $agentImage,
            $agentBackgroundInstructions,
            $agentStepInstructions,
            $agentOutputInstructions,
            $remoteContentAssetsManifestUrls
        );
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    /**
     * @param list<string>|null $remoteContentAssetsManifestUrls
     */
    public function update(
        Project              $project,
        string               $name,
        string               $gitUrl,
        string               $githubToken,
        LlmModelProvider     $llmModelProvider,
        string               $llmApiKey,
        ProjectType          $projectType = ProjectType::DEFAULT,
        ContentEditorBackend $contentEditorBackend = ContentEditorBackend::Llm,
        string               $agentImage = Project::DEFAULT_AGENT_IMAGE,
        ?string              $agentBackgroundInstructions = null,
        ?string              $agentStepInstructions = null,
        ?string              $agentOutputInstructions = null,
        ?array               $remoteContentAssetsManifestUrls = null
    ): void {
        $project->setName($name);
        $project->setGitUrl($gitUrl);
        $project->setGithubToken($githubToken);
        $project->setLlmModelProvider($llmModelProvider);
        $project->setLlmApiKey($llmApiKey);
        $project->setProjectType($projectType);
        $project->setContentEditorBackend($contentEditorBackend);
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
     * Find all non-deleted projects.
     *
     * @return list<Project>
     */
    public function findAll(): array
    {
        /** @var list<Project> $projects */
        $projects = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Project::class, 'p')
            ->where('p.deletedAt IS NULL')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $projects;
    }

    /**
     * Find all soft-deleted projects.
     *
     * @return list<Project>
     */
    public function findAllDeleted(): array
    {
        /** @var list<Project> $projects */
        $projects = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Project::class, 'p')
            ->where('p.deletedAt IS NOT NULL')
            ->orderBy('p.deletedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $projects;
    }
}
