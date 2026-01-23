<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Infrastructure\Execution;

use App\ProjectMgmt\Facade\ProjectMgmtFacadeInterface;
use App\WorkspaceMgmt\Facade\WorkspaceMgmtFacadeInterface;
use App\WorkspaceTooling\Infrastructure\Security\PathTraversalException;
use App\WorkspaceTooling\Infrastructure\Security\SecurePathResolver;
use EtfsCodingAgent\Service\ShellOperationsServiceInterface;
use RuntimeException;

/**
 * Shell operations service that executes commands in isolated Docker containers.
 *
 * This service provides filesystem isolation by:
 * - Running each command in an ephemeral Docker container
 * - Mounting only the specific workspace directory
 * - Using the project's configured agent image
 * - Validating paths are within the workspace root
 */
final class IsolatedShellExecutor implements ShellOperationsServiceInterface
{
    public function __construct(
        private readonly DockerExecutor               $dockerExecutor,
        private readonly SecurePathResolver           $pathResolver,
        private readonly ProjectMgmtFacadeInterface   $projectMgmtFacade,
        private readonly WorkspaceMgmtFacadeInterface $workspaceMgmtFacade
    ) {
    }

    /**
     * Run a command in an isolated Docker container.
     *
     * @param string $workingDirectory The workspace directory to run the command in
     * @param string $command          The command to execute
     *
     * @return string Combined stdout and stderr output
     *
     * @throws PathTraversalException   if working directory is outside workspace root
     * @throws DockerExecutionException if Docker execution fails
     */
    public function runCommand(string $workingDirectory, string $command): string
    {
        // Validate working directory is within workspace root
        if (!$this->pathResolver->isWithinWorkspaceRoot($workingDirectory)) {
            throw new PathTraversalException($workingDirectory, 'workspace root');
        }

        // Resolve the Docker image for this workspace
        $image = $this->resolveImageForWorkspace($workingDirectory);

        // Execute command in isolated container
        // Allow network for npm install, etc.
        return $this->dockerExecutor->run(
            $image,
            $command,
            $workingDirectory,
            300,
            true
        );
    }

    /**
     * Resolve the Docker image to use for a workspace.
     *
     * @param string $workspacePath Full path to the workspace directory
     *
     * @return string Docker image name with tag
     */
    private function resolveImageForWorkspace(string $workspacePath): string
    {
        // Extract workspace ID from path
        $workspaceId = $this->pathResolver->extractWorkspaceId($workspacePath);

        if ($workspaceId === null) {
            throw new RuntimeException(sprintf(
                'Could not extract workspace ID from path: %s',
                $workspacePath
            ));
        }

        // Get workspace info
        $workspace = $this->workspaceMgmtFacade->getWorkspaceById($workspaceId);

        if ($workspace === null) {
            throw new RuntimeException(sprintf(
                'Workspace not found: %s',
                $workspaceId
            ));
        }

        // Get project info with agent image
        $project = $this->projectMgmtFacade->getProjectInfo($workspace->projectId);

        return $project->agentImage;
    }
}
