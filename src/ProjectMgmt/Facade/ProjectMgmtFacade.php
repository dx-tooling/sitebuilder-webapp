<?php

declare(strict_types=1);

namespace App\ProjectMgmt\Facade;

use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\Prefab\Facade\Dto\PrefabDto;
use App\ProjectMgmt\Domain\Entity\Project;
use App\ProjectMgmt\Domain\Service\ProjectService;
use App\ProjectMgmt\Domain\ValueObject\AgentConfigTemplate;
use App\ProjectMgmt\Facade\Dto\AgentConfigTemplateDto;
use App\ProjectMgmt\Facade\Dto\ExistingLlmApiKeyDto;
use App\ProjectMgmt\Facade\Dto\ProjectInfoDto;
use App\ProjectMgmt\Facade\Enum\ProjectType;
use App\WorkspaceMgmt\Infrastructure\Service\GitHubUrlServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

use function mb_strlen;
use function mb_substr;

/**
 * Facade implementation for project management.
 * Exposes read-only operations to other verticals.
 */
final class ProjectMgmtFacade implements ProjectMgmtFacadeInterface
{
    public function __construct(
        private readonly EntityManagerInterface    $entityManager,
        private readonly GitHubUrlServiceInterface $gitHubUrlService,
        private readonly ProjectService            $projectService,
    ) {
    }

    public function createProjectFromPrefab(string $organizationId, PrefabDto $prefab): string
    {
        $llmModelProvider = LlmModelProvider::tryFrom($prefab->llmModelProvider);
        if ($llmModelProvider === null) {
            throw new RuntimeException('Invalid prefab llm_model_provider: ' . $prefab->llmModelProvider);
        }

        $project = $this->projectService->create(
            $organizationId,
            $prefab->name,
            $prefab->projectLink,
            $prefab->githubAccessKey,
            $llmModelProvider,
            $prefab->llmApiKey,
            ProjectType::DEFAULT,
            Project::DEFAULT_AGENT_IMAGE,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $prefab->keysVisible
        );

        $projectId = $project->getId();
        if ($projectId === null) {
            throw new RuntimeException('Prefab project creation failed: missing project ID.');
        }

        return $projectId;
    }

    public function getProjectInfo(string $id): ProjectInfoDto
    {
        $project = $this->entityManager->find(Project::class, $id);

        if ($project === null) {
            throw new RuntimeException('Project not found: ' . $id);
        }

        return $this->toDto($project);
    }

    /**
     * @return list<ProjectInfoDto>
     */
    public function getProjectInfos(): array
    {
        /** @var list<Project> $projects */
        $projects = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Project::class, 'p')
            ->where('p.deletedAt IS NULL')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();

        $dtos = [];
        foreach ($projects as $project) {
            $dtos[] = $this->toDto($project);
        }

        return $dtos;
    }

    /**
     * Returns unique LLM API keys with their abbreviated form and associated project names.
     * Used for the "reuse existing key" feature in the project form.
     * Only includes keys from non-deleted projects belonging to the specified organization.
     *
     * SECURITY: This method filters by organizationId to prevent cross-organization key leakage.
     *
     * @return list<ExistingLlmApiKeyDto>
     */
    public function getExistingLlmApiKeys(string $organizationId): array
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

        // Group projects by API key (exclude projects where keys are not visible to users)
        /** @var array<string, list<string>> $keyToProjects */
        $keyToProjects = [];
        foreach ($projects as $project) {
            if (!$project->isKeysVisible()) {
                continue;
            }
            $apiKey = $project->getLlmApiKey();
            if ($apiKey === '') {
                continue;
            }
            $keyToProjects[$apiKey][] = $project->getName();
        }

        // Convert to DTOs
        $dtos = [];
        foreach ($keyToProjects as $apiKey => $projectNames) {
            $dtos[] = new ExistingLlmApiKeyDto(
                $apiKey,
                $this->abbreviateApiKey($apiKey),
                $projectNames
            );
        }

        return $dtos;
    }

    private function toDto(Project $project): ProjectInfoDto
    {
        $id = $project->getId();
        if ($id === null) {
            throw new RuntimeException('Project ID cannot be null');
        }

        $githubUrl = $this->gitHubUrlService->getRepositoryUrl($project->getGitUrl());

        return new ProjectInfoDto(
            $id,
            $project->getName(),
            $project->getGitUrl(),
            $project->getGithubToken(),
            $project->getProjectType(),
            $project->getContentEditorBackend(),
            $githubUrl,
            $project->getAgentImage(),
            $project->getLlmModelProvider(),
            $project->getLlmApiKey(),
            $project->getAgentBackgroundInstructions(),
            $project->getAgentStepInstructions(),
            $project->getAgentOutputInstructions(),
            $project->getRemoteContentAssetsManifestUrls(),
            $project->getS3BucketName(),
            $project->getS3Region(),
            $project->getS3AccessKeyId(),
            $project->getS3SecretAccessKey(),
            $project->getS3IamRoleArn(),
            $project->getS3KeyPrefix(),
        );
    }

    public function getAgentConfigTemplate(ProjectType $type): AgentConfigTemplateDto
    {
        $template = AgentConfigTemplate::forProjectType($type);

        return new AgentConfigTemplateDto(
            $template->backgroundInstructions,
            $template->stepInstructions,
            $template->outputInstructions,
        );
    }

    /**
     * Abbreviates an API key for display: first 6 chars + "..." + last 6 chars.
     */
    private function abbreviateApiKey(string $apiKey): string
    {
        $length = mb_strlen($apiKey);
        if ($length <= 15) {
            // Too short to abbreviate meaningfully
            return $apiKey;
        }

        return mb_substr($apiKey, 0, 6) . '...' . mb_substr($apiKey, -6);
    }
}
