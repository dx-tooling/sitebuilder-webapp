<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Facade;

use App\WorkspaceMgmt\Facade\Dto\WorkspaceInfoDto;

/**
 * Facade for workspace management operations.
 * Used by ChatBasedContentEditor for workspace lifecycle and git operations.
 */
interface WorkspaceMgmtFacadeInterface
{
    /**
     * Get a workspace by its ID.
     */
    public function getWorkspaceById(string $workspaceId): ?WorkspaceInfoDto;

    /**
     * Get the workspace for a project.
     */
    public function getWorkspaceForProject(string $projectId): ?WorkspaceInfoDto;

    /**
     * Ensure a workspace exists and is ready for a conversation.
     * Creates workspace if missing, runs setup if needed.
     * This is the main entry point for ChatBasedContentEditor.
     */
    public function ensureWorkspaceReadyForConversation(string $projectId): WorkspaceInfoDto;

    /**
     * Transition workspace to IN_CONVERSATION status.
     * Called when a new conversation starts.
     */
    public function transitionToInConversation(string $workspaceId): void;

    /**
     * Transition workspace to AVAILABLE_FOR_CONVERSATION status.
     * Called when a conversation finishes.
     */
    public function transitionToAvailableForConversation(string $workspaceId): void;

    /**
     * Transition workspace to IN_REVIEW status.
     * Called when conversation is sent to review.
     */
    public function transitionToInReview(string $workspaceId): void;

    /**
     * Reset a PROBLEM workspace back to AVAILABLE_FOR_SETUP.
     */
    public function resetProblemWorkspace(string $workspaceId): void;

    /**
     * Reset any workspace to AVAILABLE_FOR_SETUP.
     * Used when user wants to start fresh with a new clone.
     */
    public function resetWorkspaceForSetup(string $workspaceId): void;

    /**
     * Commit all changes and push to remote.
     * Called after edit sessions complete.
     */
    public function commitAndPush(string $workspaceId, string $message): void;

    /**
     * Ensure a pull request exists for the workspace branch.
     *
     * @return string the PR URL
     */
    public function ensurePullRequest(string $workspaceId): string;
}
