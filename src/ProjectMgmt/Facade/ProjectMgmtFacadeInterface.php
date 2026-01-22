<?php

declare(strict_types=1);

namespace App\ProjectMgmt\Facade;

use App\ProjectMgmt\Facade\Dto\ProjectInfoDto;

interface ProjectMgmtFacadeInterface
{
    public function createProject(
        string $name,
        string $gitUrl,
        string $githubToken
    ): void;

    /** @return list<ProjectInfoDto> */
    public function getProjectInfos(): array;

    public function getProjectInfo(string $id): ProjectInfoDto;
}
