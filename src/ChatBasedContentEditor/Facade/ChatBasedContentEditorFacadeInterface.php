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
     * @param int|null $timeoutMinutes number of minutes after which a conversation is considered stale (default: configured session_timeout_minutes)
     *
     * @return list<string> list of workspace IDs that were released
     */
    public function releaseStaleConversations(?int $timeoutMinutes = null): array;

    /**
     * Get the ID of the latest conversation for a workspace.
     * Returns the most recent conversation regardless of status.
     */
    public function getLatestConversationId(string $workspaceId): ?string;

    /**
     * Recover edit sessions stuck in non-terminal states.
     *
     * - Sessions in Running state for longer than $runningTimeoutMinutes are marked as Failed.
     * - Sessions in Cancelling state for longer than $cancellingTimeoutMinutes are marked as Cancelled.
     *
     * A done chunk is written for each recovered session so the frontend polling loop terminates.
     *
     * @return int the number of sessions recovered
     */
    public function recoverStuckEditSessions(int $runningTimeoutMinutes = 30, int $cancellingTimeoutMinutes = 2): int;
}
