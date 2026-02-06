<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Infrastructure\Execution;

use App\WorkspaceTooling\Facade\StreamingProcessInterface;
use Symfony\Component\Process\Process;

/**
 * Wrapper for an asynchronously running Docker process.
 *
 * Provides a clean interface for monitoring process execution and handling completion.
 */
final class StreamingDockerProcess implements StreamingProcessInterface
{
    private string $output = '';

    public function __construct(
        private readonly Process $process,
        private readonly string  $command,
        private readonly string  $image
    ) {
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    public function wait(): void
    {
        $this->process->wait();
    }

    /**
     * Check the process result and throw exceptions for Docker-level failures.
     *
     * @throws DockerExecutionException if Docker execution failed
     */
    public function checkResult(): void
    {
        if ($this->process->isRunning()) {
            return;
        }

        if (!$this->process->isSuccessful()) {
            $exitCode    = $this->process->getExitCode();
            $errorOutput = $this->process->getErrorOutput();

            if (str_contains($errorOutput, 'Unable to find image')) {
                throw new DockerExecutionException(
                    sprintf('Docker image not found: %s', $this->image),
                    $this->command
                );
            }

            if (str_contains($errorOutput, 'permission denied')) {
                throw new DockerExecutionException(
                    'Docker permission denied. Ensure the Docker socket is accessible.',
                    $this->command
                );
            }

            // Only throw for Docker-level failures (exit code 125-127 are Docker errors)
            if ($exitCode !== null && $exitCode >= 125) {
                throw new DockerExecutionException(
                    sprintf('Docker execution failed with exit code %d: %s', $exitCode, $errorOutput),
                    $this->command
                );
            }
        }
    }

    public function appendOutput(string $buffer): void
    {
        $this->output .= $buffer;
    }

    public function getOutput(): string
    {
        return $this->output;
    }

    public function getExitCode(): ?int
    {
        return $this->process->getExitCode();
    }

    public function isSuccessful(): bool
    {
        return $this->process->isSuccessful();
    }
}
