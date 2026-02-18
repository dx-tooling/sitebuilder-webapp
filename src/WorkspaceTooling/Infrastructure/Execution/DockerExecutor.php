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
 * - Mounting only the specific workspace directory as /workspace
 * - Optionally disabling network access
 *
 * When running Docker commands from inside a container (Docker-in-Docker via socket),
 * volume mount paths must be translated to host paths since the Docker daemon
 * interprets paths relative to the host, not the container.
 */
final class DockerExecutor
{
    private const int DEFAULT_TIMEOUT = 300; // 5 minutes

    public function __construct(
        private readonly string $containerBasePath,
        private readonly string $hostBasePath
    ) {
    }

    /**
     * Run a command in an isolated Docker container.
     *
     * @param string      $image            Docker image to use (e.g., node:22-slim)
     * @param string      $command          Command to execute inside the container
     * @param string      $mountPath        Path to mount as /workspace (container path, will be translated)
     * @param string      $workingDirectory Working directory inside the container (e.g., /workspace)
     * @param int         $timeout          Timeout in seconds
     * @param bool        $allowNetwork     Whether to allow network access
     * @param string|null $containerName    Optional container name for identification
     *
     * @return string Combined stdout and stderr output
     *
     * @throws DockerExecutionException if command execution fails
     */
    public function run(
        string    $image,
        string    $command,
        string    $mountPath,
        string    $workingDirectory = '/workspace',
        int       $timeout = self::DEFAULT_TIMEOUT,
        bool      $allowNetwork = true,
        ?string   $containerName = null,
        ?callable $outputCallback = null
    ): string {
        $dockerCommand = $this->buildDockerCommand(
            $image,
            $command,
            $mountPath,
            $workingDirectory,
            $allowNetwork,
            $containerName
        );

        $process = new Process($dockerCommand);
        $process->setTimeout($timeout);

        try {
            $output = '';
            $process->run(function (string $type, string $buffer) use (&$output, $outputCallback): void {
                $output .= $buffer;

                if ($outputCallback !== null) {
                    $outputCallback($buffer, $type === Process::ERR);
                }
            });
        } catch (ProcessTimedOutException $e) {
            throw new DockerExecutionException(
                sprintf('Command timed out after %d seconds', $timeout),
                $command,
                $e
            );
        }

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
     * Start a command asynchronously in an isolated Docker container.
     *
     * Returns a StreamingDockerProcess that can be polled for completion.
     * Output is streamed to the callback as it arrives.
     *
     * @param string        $image            Docker image to use (e.g., node:22-slim)
     * @param string        $command          Command to execute inside the container
     * @param string        $mountPath        Path to mount as /workspace (container path, will be translated)
     * @param string        $workingDirectory Working directory inside the container (e.g., /workspace)
     * @param int           $timeout          Timeout in seconds
     * @param bool          $allowNetwork     Whether to allow network access
     * @param string|null   $containerName    Optional container name for identification
     * @param callable|null $outputCallback   Callback for streaming output: fn(string $buffer, bool $isError): void
     *
     * @return StreamingDockerProcess The running process wrapper
     */
    public function startAsync(
        string    $image,
        string    $command,
        string    $mountPath,
        string    $workingDirectory = '/workspace',
        int       $timeout = self::DEFAULT_TIMEOUT,
        bool      $allowNetwork = true,
        ?string   $containerName = null,
        ?callable $outputCallback = null
    ): StreamingDockerProcess {
        $dockerCommand = $this->buildDockerCommand(
            $image,
            $command,
            $mountPath,
            $workingDirectory,
            $allowNetwork,
            $containerName
        );

        $process = new Process($dockerCommand);
        $process->setTimeout($timeout);

        $streamingProcess = new StreamingDockerProcess($process, $command, $image);

        // Start with output callback
        $process->start(function (string $type, string $buffer) use ($streamingProcess, $outputCallback): void {
            $streamingProcess->appendOutput($buffer);

            if ($outputCallback !== null) {
                $outputCallback($buffer, $type === Process::ERR);
            }
        });

        return $streamingProcess;
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
        string  $image,
        string  $command,
        string  $mountPath,
        string  $workingDirectory,
        bool    $allowNetwork,
        ?string $containerName
    ): array {
        // Translate container path to host path for Docker volume mount
        $hostPath = $this->translateToHostPath($mountPath);

        $dockerCmd = [
            'docker', 'run',
            '--rm',                          // Remove container after execution
            '-i',                            // Keep stdin open
            '--workdir=' . $workingDirectory, // Set working directory
            '-v', $hostPath . ':/workspace', // Mount workspace (using host path)
        ];

        // Add container name for identification (with unique suffix to allow concurrent runs)
        if ($containerName !== null) {
            $uniqueSuffix = substr(bin2hex(random_bytes(4)), 0, 8);
            $dockerCmd[]  = '--name=' . $containerName . '-' . $uniqueSuffix;
        }

        // Disable network if not needed (more secure, but prevents npm install etc.)
        if (!$allowNetwork) {
            $dockerCmd[] = '--network=none';
        }

        // Add resource limits for safety
        // 2GB memory to handle webpack/build processes that need more heap
        $dockerCmd[] = '--memory=2g';
        $dockerCmd[] = '--cpus=2';

        // Increase Node.js heap size for webpack builds
        $dockerCmd[] = '-e';
        $dockerCmd[] = 'NODE_OPTIONS=--max-old-space-size=1536';

        // Add the image
        $dockerCmd[] = $image;

        // Add the command to execute
        $dockerCmd[] = 'sh';
        $dockerCmd[] = '-c';
        $dockerCmd[] = $command;

        return $dockerCmd;
    }

    /**
     * Translate a container path to a host path for Docker volume mounts.
     *
     * When running Docker commands from inside a container (Docker-in-Docker),
     * the Docker daemon runs on the host and interprets volume paths relative
     * to the host filesystem. This method translates container paths to host paths.
     *
     * Example: /var/www/public/workspaces/123 -> /home/user/project/public/workspaces/123
     */
    private function translateToHostPath(string $containerPath): string
    {
        // If paths are the same, no translation needed (running on host)
        if ($this->containerBasePath === $this->hostBasePath) {
            return $containerPath;
        }

        // Replace container base path with host base path
        if (str_starts_with($containerPath, $this->containerBasePath)) {
            return $this->hostBasePath . substr($containerPath, strlen($this->containerBasePath));
        }

        // Path doesn't start with container base - return as-is
        return $containerPath;
    }
}
