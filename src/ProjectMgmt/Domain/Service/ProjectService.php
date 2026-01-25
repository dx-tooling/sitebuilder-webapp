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

    public function create(
        string           $name,
        string           $gitUrl,
        string           $githubToken,
        LlmModelProvider $llmModelProvider,
        string           $llmApiKey,
        ProjectType      $projectType = ProjectType::DEFAULT,
        string           $agentImage = Project::DEFAULT_AGENT_IMAGE,
        ?string          $agentBackgroundInstructions = null,
        ?string          $agentStepInstructions = null,
        ?string          $agentOutputInstructions = null
    ): Project {
        $project = new Project(
            $name,
            $gitUrl,
            $githubToken,
            $llmModelProvider,
            $llmApiKey,
            $projectType,
            $agentImage,
            $agentBackgroundInstructions,
            $agentStepInstructions,
            $agentOutputInstructions
        );
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    public function update(
        Project          $project,
        string           $name,
        string           $gitUrl,
        string           $githubToken,
        LlmModelProvider $llmModelProvider,
        string           $llmApiKey,
        ProjectType      $projectType = ProjectType::DEFAULT,
        string           $agentImage = Project::DEFAULT_AGENT_IMAGE,
        ?string          $agentBackgroundInstructions = null,
        ?string          $agentStepInstructions = null,
        ?string          $agentOutputInstructions = null
    ): void {
        $project->setName($name);
        $project->setGitUrl($gitUrl);
        $project->setGithubToken($githubToken);
        $project->setLlmModelProvider($llmModelProvider);
        $project->setLlmApiKey($llmApiKey);
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

        $this->entityManager->flush();
    }

    public function findById(string $id): ?Project
    {
        return $this->entityManager->find(Project::class, $id);
    }

    /**
     * @return list<Project>
     */
    public function findAll(): array
    {
        /** @var list<Project> $projects */
        $projects = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Project::class, 'p')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $projects;
    }
}
