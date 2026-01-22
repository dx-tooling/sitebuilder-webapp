<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Facade;

use App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus;

interface WorkspaceMgmtFacadeInterface
{
    public function getCurrentWorkspace(string $projectId);

    public function changeToStatus(string $workspaceId, WorkspaceStatus $newStatus): bool;

    public function isConversationPossible(string $workspaceId): bool;
}
