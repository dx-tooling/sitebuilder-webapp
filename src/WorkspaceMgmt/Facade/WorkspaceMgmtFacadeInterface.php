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
     * Ensure a workspace exists and dispatch async setup if needed.
     * Returns immediately with workspace info. Check workspace status
     * to determine if setup is in progress.
     *
     * @param string $projectId the project ID
     * @param string $userEmail email of the user triggering setup (used for human-friendly branch name)
     *
     * @return WorkspaceInfoDto workspace info (status may be IN_SETUP if setup was dispatched)
     */
    public function dispatchSetupIfNeeded(string $projectId, string $userEmail): WorkspaceInfoDto;

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
     * Delete a workspace entirely.
     * Used when deleting a project.
     */
    public function deleteWorkspace(string $workspaceId): void;

    /**
     * Commit all changes and push to remote.
     * Called after edit sessions complete.
     *
     * @param string      $workspaceId     the workspace ID
     * @param string      $message         the commit message
     * @param string      $authorEmail     the author's email address for the commit
     * @param string|null $conversationId  optional conversation ID to link
     * @param string|null $conversationUrl optional conversation URL to link
     */
    public function commitAndPush(
        string  $workspaceId,
        string  $message,
        string  $authorEmail,
        ?string $conversationId = null,
        ?string $conversationUrl = null
    ): void;

    /**
     * Ensure a pull request exists for the workspace branch.
     *
     * @param string      $workspaceId     the workspace ID
     * @param string|null $conversationId  optional conversation ID to link
     * @param string|null $conversationUrl optional conversation URL to link
     * @param string|null $userEmail       optional user email to include
     *
     * @return string the PR URL
     */
    public function ensurePullRequest(
        string  $workspaceId,
        ?string $conversationId = null,
        ?string $conversationUrl = null,
        ?string $userEmail = null
    ): string;

    /**
     * Read a file from the workspace.
     *
     * @param string $workspaceId  the workspace ID
     * @param string $relativePath the relative path within the workspace
     *
     * @return string the file contents
     */
    public function readWorkspaceFile(string $workspaceId, string $relativePath): string;

    /**
     * Write a file to the workspace.
     *
     * @param string $workspaceId  the workspace ID
     * @param string $relativePath the relative path within the workspace
     * @param string $content      the content to write
     */
    public function writeWorkspaceFile(string $workspaceId, string $relativePath, string $content): void;

    /**
     * Run the build process (npm run build) in the workspace.
     *
     * This compiles source files (from /src) to distribution files (to /dist).
     * Used after manual HTML edits to update the dist folder.
     *
     * @param string $workspaceId the workspace ID
     *
     * @return string the build output
     */
    public function runBuild(string $workspaceId): string;
}
