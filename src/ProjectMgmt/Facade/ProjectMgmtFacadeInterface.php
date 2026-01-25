<?php

declare(strict_types=1);

namespace App\ProjectMgmt\Facade;

use App\ProjectMgmt\Facade\Dto\ExistingLlmApiKeyDto;
use App\ProjectMgmt\Facade\Dto\ProjectInfoDto;

/**
 * Facade for project management operations.
 * Exposes read-only project information to other verticals.
 * Project creation/update is handled internally by ProjectService.
 */
interface ProjectMgmtFacadeInterface
{
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
     * @return list<ExistingLlmApiKeyDto>
     */
    public function getExistingLlmApiKeys(): array;
}
