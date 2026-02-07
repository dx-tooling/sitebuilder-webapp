<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Facade;

/**
 * Interface for setting agent execution context.
 *
 * This allows other verticals to set context information (workspace ID, path, conversation ID,
 * project name, agent image) that will be used during agent execution.
 */
interface AgentExecutionContextInterface
{
    /**
     * Set the execution context for the current agent run.
     *
     * @param string            $workspaceId                     Workspace UUID
     * @param string            $workspacePath                   Actual filesystem path to the workspace
     * @param string|null       $conversationId                  Conversation UUID
     * @param string|null       $projectName                     Project name for container naming
     * @param string|null       $agentImage                      Docker image to use for agent containers
     * @param list<string>|null $remoteContentAssetsManifestUrls URLs to manifest.json (or similar) for remote content assets
     */
    public function setContext(
        string  $workspaceId,
        string  $workspacePath,
        ?string $conversationId,
        ?string $projectName,
        ?string $agentImage,
        ?array  $remoteContentAssetsManifestUrls = null
    ): void;

    /**
     * Clear the execution context.
     */
    public function clearContext(): void;

    /**
     * Get the workspace ID for the current agent run.
     */
    public function getWorkspaceId(): ?string;

    /**
     * Get the conversation ID for the current agent run.
     */
    public function getConversationId(): ?string;

    /**
     * Get remote content assets manifest URLs configured for the current project.
     *
     * @return list<string>
     */
    public function getRemoteContentAssetsManifestUrls(): array;

    /**
     * Set a suggested commit message from the agent.
     *
     * The agent can call this to suggest an optimal commit message
     * describing the changes made during the edit session.
     */
    public function setSuggestedCommitMessage(string $message): void;

    /**
     * Get the suggested commit message, if any.
     *
     * @return string|null The suggested message, or null if none was set
     */
    public function getSuggestedCommitMessage(): ?string;
}
