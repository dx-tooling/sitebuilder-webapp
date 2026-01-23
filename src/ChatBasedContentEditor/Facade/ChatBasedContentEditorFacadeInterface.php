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

    /**
     * Get the user ID of the ongoing conversation for a workspace.
     * Returns null if no ongoing conversation exists.
     */
    public function getOngoingConversationUserId(string $workspaceId): ?string;

    /**
     * Release stale conversations by finishing them and making workspaces available.
     * A conversation is considered stale if the user hasn't sent a heartbeat
     * (updated lastActivityAt) within the specified timeout.
     *
     * @param int $timeoutMinutes number of minutes after which a conversation is considered stale
     *
     * @return list<string> list of workspace IDs that were released
     */
    public function releaseStaleConversations(int $timeoutMinutes = 5): array;
}
