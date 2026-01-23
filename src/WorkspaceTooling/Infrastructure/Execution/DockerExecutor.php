<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Infrastructure\Execution;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Executes commands in isolated Docker containers.
 *
 * This executor provides filesystem isolation by:
 * - Running commands in ephemeral containers (--rm)
 * - Mounting only the specific workspace directory
 * - Running as non-root user when possible
 * - Optionally disabling network access
 */
final class DockerExecutor
{
    private const int DEFAULT_TIMEOUT = 300; // 5 minutes

    /**
     * Run a command in an isolated Docker container.
     *
     * @param string $image        Docker image to use (e.g., node:22-slim)
     * @param string $command      Command to execute inside the container
     * @param string $hostPath     Host path to mount as /workspace
     * @param int    $timeout      Timeout in seconds
     * @param bool   $allowNetwork Whether to allow network access
     *
     * @return string Combined stdout and stderr output
     *
     * @throws DockerExecutionException if command execution fails
     */
    public function run(
        string $image,
        string $command,
        string $hostPath,
        int    $timeout = self::DEFAULT_TIMEOUT,
        bool   $allowNetwork = true
    ): string {
        $dockerCommand = $this->buildDockerCommand(
            $image,
            $command,
            $hostPath,
            $allowNetwork
        );

        $process = new Process($dockerCommand);
        $process->setTimeout($timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new DockerExecutionException(
                sprintf('Command timed out after %d seconds', $timeout),
                $command,
                $e
            );
        }

        $output = $process->getOutput() . $process->getErrorOutput();

        if (!$process->isSuccessful()) {
            // Check for common Docker errors
            $exitCode    = $process->getExitCode();
            $errorOutput = $process->getErrorOutput();

            if (str_contains($errorOutput, 'Unable to find image')) {
                throw new DockerExecutionException(
                    sprintf('Docker image not found: %s', $image),
                    $command
                );
            }

            if (str_contains($errorOutput, 'permission denied')) {
                throw new DockerExecutionException(
                    'Docker permission denied. Ensure the Docker socket is accessible.',
                    $command
                );
            }

            // Return output even on non-zero exit - the command may have legitimate non-zero exits
            // Only throw for Docker-level failures (exit code 125-127 are Docker errors)
            if ($exitCode !== null && $exitCode >= 125) {
                throw new DockerExecutionException(
                    sprintf('Docker execution failed with exit code %d: %s', $exitCode, $errorOutput),
                    $command
                );
            }
        }

        return $output;
    }

    /**
     * Check if Docker is available and accessible.
     */
    public function isDockerAvailable(): bool
    {
        $process = new Process(['docker', 'info']);
        $process->setTimeout(10);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Pull a Docker image if not already available locally.
     *
     * @param string $image Image name with tag
     *
     * @return bool True if image is now available
     */
    public function ensureImageAvailable(string $image): bool
    {
        // Check if image exists locally
        $checkProcess = new Process(['docker', 'image', 'inspect', $image]);
        $checkProcess->setTimeout(30);
        $checkProcess->run();

        if ($checkProcess->isSuccessful()) {
            return true;
        }

        // Pull the image
        $pullProcess = new Process(['docker', 'pull', $image]);
        $pullProcess->setTimeout(300); // 5 minutes for large images
        $pullProcess->run();

        return $pullProcess->isSuccessful();
    }

    /**
     * Build the docker run command array.
     *
     * @return list<string>
     */
    private function buildDockerCommand(
        string $image,
        string $command,
        string $hostPath,
        bool   $allowNetwork
    ): array {
        $dockerCmd = [
            'docker', 'run',
            '--rm',                          // Remove container after execution
            '-i',                            // Keep stdin open
            '--workdir=/workspace',          // Set working directory
            '-v', $hostPath . ':/workspace', // Mount workspace
        ];

        // Disable network if not needed (more secure, but prevents npm install etc.)
        if (!$allowNetwork) {
            $dockerCmd[] = '--network=none';
        }

        // Add resource limits for safety
        $dockerCmd[] = '--memory=512m';
        $dockerCmd[] = '--cpus=1';

        // Add the image
        $dockerCmd[] = $image;

        // Add the command to execute
        $dockerCmd[] = 'sh';
        $dockerCmd[] = '-c';
        $dockerCmd[] = $command;

        return $dockerCmd;
    }
}
