<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Facade;

use RuntimeException;

/**
 * Interface for an asynchronously running process that streams output.
 *
 * Provides a clean interface for monitoring process execution and handling completion.
 */
interface StreamingProcessInterface
{
    /**
     * Check if the process is still running.
     */
    public function isRunning(): bool;

    /**
     * Wait for the process to complete.
     */
    public function wait(): void;

    /**
     * Check the process result and throw exceptions for execution failures.
     *
     * @throws RuntimeException if execution failed
     */
    public function checkResult(): void;

    /**
     * Get the exit code of the process.
     *
     * @return int|null The exit code, or null if the process is still running
     */
    public function getExitCode(): ?int;

    /**
     * Check if the process completed successfully.
     */
    public function isSuccessful(): bool;

    /**
     * Get the combined output of the process.
     */
    public function getOutput(): string;
}
