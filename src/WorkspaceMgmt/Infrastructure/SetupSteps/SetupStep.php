<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Infrastructure\SetupSteps;

/**
 * Represents a single setup step to be executed in a workspace.
 */
final readonly class SetupStep
{
    /**
     * @param string       $name      Human-readable name for logging
     * @param string       $command   Shell command to execute
     * @param list<string> $arguments Command arguments
     * @param int|null     $timeout   Timeout in seconds (null for default)
     */
    public function __construct(
        public string $name,
        public string $command,
        public array  $arguments = [],
        public ?int   $timeout = null,
    ) {
    }

    /**
     * Get the full command line for display/logging purposes.
     */
    public function getCommandLine(): string
    {
        if ($this->arguments === []) {
            return $this->command;
        }

        return $this->command . ' ' . implode(' ', $this->arguments);
    }
}
