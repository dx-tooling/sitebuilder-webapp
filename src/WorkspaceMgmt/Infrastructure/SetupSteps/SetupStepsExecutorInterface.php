<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Infrastructure\SetupSteps;

/**
 * Interface for executing setup steps.
 */
interface SetupStepsExecutorInterface
{
    /**
     * Execute a list of setup steps in the given workspace path.
     *
     * @param list<SetupStep> $steps
     */
    public function execute(array $steps, string $workspacePath): void;
}
