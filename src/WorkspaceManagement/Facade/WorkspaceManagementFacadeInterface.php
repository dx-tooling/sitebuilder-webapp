<?php

declare(strict_types=1);

namespace App\WorkspaceManagement\Facade;

use App\WorkspaceManagement\Facade\Dto\CommandResultDto;
use App\WorkspaceManagement\Facade\Dto\FileListDto;
use App\WorkspaceManagement\Facade\Dto\WorkspaceDto;

interface WorkspaceManagementFacadeInterface
{
    public function createWorkspace(string $projectId): WorkspaceDto;

    public function destroyWorkspace(string $workspaceId): void;

    public function executeCommand(string $workspaceId, string $command): CommandResultDto;

    public function readFile(string $workspaceId, string $path): ?string;

    public function writeFile(string $workspaceId, string $path, string $content): void;

    public function listFiles(string $workspaceId, string $path): FileListDto;

    public function getWorkspaceForProject(string $projectId): ?WorkspaceDto;
}
