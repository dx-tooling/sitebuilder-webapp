<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Facade\Dto;

use App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus;

final readonly class WorkspaceInfoDto
{
    public function __construct(
        public string          $id,
        public string          $projectId,
        public string          $projectName,
        public WorkspaceStatus $status,
        public ?string         $branchName,
        public string          $workspacePath,
    ) {
    }
}
