<?php

declare(strict_types=1);

namespace App\ProjectMgmt\Facade;

use App\Prefab\Facade\Dto\PrefabDto;
use App\ProjectMgmt\Facade\Dto\AgentConfigTemplateDto;
use App\ProjectMgmt\Facade\Dto\ExistingLlmApiKeyDto;
use App\ProjectMgmt\Facade\Dto\ProjectInfoDto;
use App\ProjectMgmt\Facade\Enum\ProjectType;

/**
 * Facade for project management operations.
 * Exposes read-only project information to other verticals.
 * Project creation/update is handled internally by ProjectService.
 */
interface ProjectMgmtFacadeInterface
{
    /**
     * Create a project from a prefab definition (used when a new organization is created).
     */
    public function createProjectFromPrefab(string $organizationId, PrefabDto $prefab): string;

    /**
     * Get project information by ID.
     */
    public function getProjectInfo(string $id): ProjectInfoDto;

    /**
     * Get all projects.
     *
     * @return list<ProjectInfoDto>
     */
    public function getProjectInfos(): array;

    /**
     * Get unique LLM API keys with their abbreviated form and associated project names.
     * Used for the "reuse existing key" feature.
     *
     * Only returns keys from projects belonging to the specified organization.
     * This is a security boundary - keys must never leak across organizations.
     *
     * @return list<ExistingLlmApiKeyDto>
     */
    public function getExistingLlmApiKeys(string $organizationId): array;

    /**
     * Get the default agent configuration template for a given project type.
     * Used to pre-fill the agent configuration form during project creation.
     */
    public function getAgentConfigTemplate(ProjectType $type): AgentConfigTemplateDto;
}
