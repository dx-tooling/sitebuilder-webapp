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
     * @param string      $workspaceId    Workspace UUID
     * @param string      $workspacePath  Actual filesystem path to the workspace
     * @param string|null $conversationId Conversation UUID
     * @param string|null $projectName    Project name for container naming
     * @param string|null $agentImage     Docker image to use for agent containers
     */
    public function setContext(
        string  $workspaceId,
        string  $workspacePath,
        ?string $conversationId,
        ?string $projectName,
        ?string $agentImage
    ): void;

    /**
     * Clear the execution context.
     */
    public function clearContext(): void;
}
