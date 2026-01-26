<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Infrastructure\Execution;

use App\WorkspaceTooling\Facade\AgentExecutionContextInterface;

/**
 * Holds context information for the current agent execution.
 *
 * This service stores execution metadata (workspace ID, conversation ID, project name)
 * that can be used by downstream services like DockerExecutor for container naming.
 *
 * The context is request-scoped and should be set at the start of agent execution.
 */
final class AgentExecutionContext implements AgentExecutionContextInterface
{
    /**
     * Docker container names cannot exceed 128 characters.
     * We reserve 9 chars for the unique suffix added by DockerExecutor (-xxxxxxxx).
     */
    private const int DOCKER_MAX_NAME_LENGTH = 128;

    private const int UNIQUE_SUFFIX_LENGTH    = 9; // dash + 8 hex chars
    private const int MAX_BASE_NAME_LENGTH    = self::DOCKER_MAX_NAME_LENGTH - self::UNIQUE_SUFFIX_LENGTH;
    private const int MAX_PROJECT_NAME_LENGTH = 20;
    private const int ID_SHORT_LENGTH         = 8;

    private ?string $workspaceId            = null;
    private ?string $workspacePath          = null;
    private ?string $conversationId         = null;
    private ?string $projectName            = null;
    private ?string $agentImage             = null;
    private ?string $suggestedCommitMessage = null;

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
    ): void {
        $this->workspaceId    = $workspaceId;
        $this->workspacePath  = $workspacePath;
        $this->conversationId = $conversationId;
        $this->projectName    = $projectName;
        $this->agentImage     = $agentImage;
    }

    /**
     * Clear the execution context.
     */
    public function clearContext(): void
    {
        $this->workspaceId            = null;
        $this->workspacePath          = null;
        $this->conversationId         = null;
        $this->projectName            = null;
        $this->agentImage             = null;
        $this->suggestedCommitMessage = null;
    }

    public function getWorkspaceId(): ?string
    {
        return $this->workspaceId;
    }

    /**
     * Get the actual filesystem path to the workspace.
     */
    public function getWorkspacePath(): ?string
    {
        return $this->workspacePath;
    }

    public function getConversationId(): ?string
    {
        return $this->conversationId;
    }

    public function getProjectName(): ?string
    {
        return $this->projectName;
    }

    public function getAgentImage(): ?string
    {
        return $this->agentImage;
    }

    /**
     * Set a suggested commit message from the agent.
     *
     * The agent can call this to suggest an optimal commit message
     * describing the changes made during the edit session.
     */
    public function setSuggestedCommitMessage(string $message): void
    {
        $this->suggestedCommitMessage = $message;
    }

    /**
     * Get the suggested commit message, if any.
     *
     * @return string|null The suggested message, or null if none was set
     */
    public function getSuggestedCommitMessage(): ?string
    {
        return $this->suggestedCommitMessage;
    }

    /**
     * Build a Docker container name from the current context.
     *
     * Format: sitebuilder-ws-{projectSlug}-{workspaceShort}-{conversationShort}
     * Example: sitebuilder-ws-my-project-019be640-a1b2c3d4
     *
     * The name is guaranteed to be within Docker's 128 char limit (including the
     * unique suffix that DockerExecutor appends).
     *
     * @return string|null Container name, or null if context is not set
     */
    public function buildContainerName(): ?string
    {
        if ($this->workspaceId === null) {
            return null;
        }

        $parts = ['sitebuilder-ws'];

        // Add normalized project name (max 20 chars)
        if ($this->projectName !== null) {
            $normalizedProject = $this->normalizeForContainerName($this->projectName, self::MAX_PROJECT_NAME_LENGTH);
            if ($normalizedProject !== '') {
                $parts[] = $normalizedProject;
            }
        }

        // Add first 8 chars of workspace ID
        $parts[] = substr($this->workspaceId, 0, self::ID_SHORT_LENGTH);

        // Add first 8 chars of conversation ID if available
        if ($this->conversationId !== null) {
            $parts[] = substr($this->conversationId, 0, self::ID_SHORT_LENGTH);
        }

        $name = implode('-', $parts);

        // Ensure we don't exceed the max length (should never happen with current structure,
        // but this is a safety net for future changes)
        if (strlen($name) > self::MAX_BASE_NAME_LENGTH) {
            $name = substr($name, 0, self::MAX_BASE_NAME_LENGTH);
            $name = rtrim($name, '-'); // Don't end with a dash
        }

        return $name;
    }

    /**
     * Normalize a string for use in Docker container names.
     *
     * Docker container names must match: [a-zA-Z0-9][a-zA-Z0-9_.-]*
     */
    private function normalizeForContainerName(string $value, int $maxLength): string
    {
        // Convert to lowercase
        $normalized = strtolower($value);

        // Replace spaces and underscores with dashes
        $normalized = preg_replace('/[\s_]+/', '-', $normalized) ?? $normalized;

        // Remove any characters that aren't alphanumeric or dashes
        $normalized = preg_replace('/[^a-z0-9-]/', '', $normalized) ?? $normalized;

        // Remove consecutive dashes
        $normalized = preg_replace('/-+/', '-', $normalized) ?? $normalized;

        // Trim dashes from start and end
        $normalized = trim($normalized, '-');

        // Truncate to max length
        if (strlen($normalized) > $maxLength) {
            $normalized = substr($normalized, 0, $maxLength);
            $normalized = rtrim($normalized, '-'); // Don't end with a dash
        }

        return $normalized;
    }
}
