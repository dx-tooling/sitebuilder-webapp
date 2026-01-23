<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Facade;

/**
 * Interface for setting agent execution context.
 *
 * This allows other verticals to set context information (workspace ID, conversation ID,
 * project name) that will be used for container naming during agent execution.
 */
interface AgentExecutionContextInterface
{
    /**
     * Set the execution context for the current agent run.
     */
    public function setContext(string $workspaceId, ?string $conversationId, ?string $projectName): void;

    /**
     * Clear the execution context.
     */
    public function clearContext(): void;
}
