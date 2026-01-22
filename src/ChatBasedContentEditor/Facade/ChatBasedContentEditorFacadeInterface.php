<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Facade;

/**
 * Facade for ChatBasedContentEditor operations.
 * Used by other verticals for conversation management.
 */
interface ChatBasedContentEditorFacadeInterface
{
    /**
     * Finish all ongoing conversations for a workspace.
     * Used when resetting a workspace for fresh setup.
     *
     * @return int the number of conversations finished
     */
    public function finishAllOngoingConversationsForWorkspace(string $workspaceId): int;
}
