<?php

declare(strict_types=1);

namespace App\ContentProjectManagement\Facade;

use App\ContentProjectManagement\Facade\Dto\ContentProjectDto;

interface ContentProjectManagementFacadeInterface
{
    public function createContentProject(string $organizationId, string $name): ContentProjectDto;

    public function getContentProject(string $projectId): ?ContentProjectDto;

    /**
     * @return list<ContentProjectDto>
     */
    public function listContentProjects(string $organizationId): array;

    public function canUserAccessProject(string $userId, string $projectId): bool;

    public function deleteContentProject(string $projectId): void;

    public function getProjectGitHubUrl(string $projectId): ?string;
}
