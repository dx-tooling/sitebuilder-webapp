<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Infrastructure\Execution;

use EtfsCodingAgent\Service\ShellOperationsServiceInterface;
use RuntimeException;

use function str_starts_with;

/**
 * Shell operations service that executes commands in isolated Docker containers.
 *
 * This service provides filesystem isolation by:
 * - Running each command in an ephemeral Docker container
 * - Mounting only the workspace directory (from execution context) as /workspace
 * - The agent sees /workspace as its working directory
 * - Using the project's configured agent image (from execution context)
 */
final class IsolatedShellExecutor implements ShellOperationsServiceInterface
{
    private const string WORKSPACE_MOUNT_POINT = '/workspace';

    public function __construct(
        private readonly DockerExecutor        $dockerExecutor,
        private readonly AgentExecutionContext $executionContext
    ) {
    }

    /**
     * Run a command in an isolated Docker container.
     *
     * The agent uses /workspace as its working directory. The actual workspace path
     * is provided by the execution context and mounted to /workspace in the container.
     *
     * @param string $workingDirectory The working directory (relative to /workspace)
     * @param string $command          The command to execute
     *
     * @return string Combined stdout and stderr output
     *
     * @throws DockerExecutionException if Docker execution fails
     * @throws RuntimeException         if execution context is not set
     */
    public function runCommand(string $workingDirectory, string $command): string
    {
        // Get workspace path and image from execution context (set by handler)
        $workspacePath = $this->executionContext->getWorkspacePath();
        $agentImage    = $this->executionContext->getAgentImage();

        if ($workspacePath === null || $agentImage === null) {
            throw new RuntimeException(
                'Execution context not set. Ensure setContext() is called before running commands.'
            );
        }

        // Validate the working directory is within /workspace
        if (!str_starts_with($workingDirectory, self::WORKSPACE_MOUNT_POINT)) {
            throw new RuntimeException(sprintf(
                'Working directory must be within %s, got: %s',
                self::WORKSPACE_MOUNT_POINT,
                $workingDirectory
            ));
        }

        // Get container name from execution context
        $containerName = $this->executionContext->buildContainerName();

        // Execute command in isolated container
        // The actual workspace path is mounted to /workspace
        $outputCallback = $this->executionContext->getOutputCallback();

        return $this->dockerExecutor->run(
            $agentImage,
            $command,
            $workspacePath,       // Actual path to mount
            $workingDirectory,    // Working directory inside container
            300,
            true,
            $containerName,
            $outputCallback
        );
    }

    /**
     * Start a command asynchronously in an isolated Docker container.
     *
     * Returns a StreamingDockerProcess that can be polled for completion.
     * Output is streamed to the callback (from execution context) as it arrives.
     *
     * @param string $workingDirectory The working directory (relative to /workspace)
     * @param string $command          The command to execute
     *
     * @return StreamingDockerProcess The running process wrapper
     *
     * @throws RuntimeException if execution context is not set
     */
    public function runCommandAsync(string $workingDirectory, string $command): StreamingDockerProcess
    {
        // Get workspace path and image from execution context (set by handler)
        $workspacePath = $this->executionContext->getWorkspacePath();
        $agentImage    = $this->executionContext->getAgentImage();

        if ($workspacePath === null || $agentImage === null) {
            throw new RuntimeException(
                'Execution context not set. Ensure setContext() is called before running commands.'
            );
        }

        // Validate the working directory is within /workspace
        if (!str_starts_with($workingDirectory, self::WORKSPACE_MOUNT_POINT)) {
            throw new RuntimeException(sprintf(
                'Working directory must be within %s, got: %s',
                self::WORKSPACE_MOUNT_POINT,
                $workingDirectory
            ));
        }

        // Get container name from execution context
        $containerName = $this->executionContext->buildContainerName();

        // Execute command in isolated container asynchronously
        $outputCallback = $this->executionContext->getOutputCallback();

        return $this->dockerExecutor->startAsync(
            $agentImage,
            $command,
            $workspacePath,       // Actual path to mount
            $workingDirectory,    // Working directory inside container
            300,
            true,
            $containerName,
            $outputCallback
        );
    }
}
