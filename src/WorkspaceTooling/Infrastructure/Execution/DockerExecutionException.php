<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Infrastructure\Execution;

use RuntimeException;
use Throwable;

/**
 * Exception thrown when Docker command execution fails.
 */
final class DockerExecutionException extends RuntimeException
{
    public function __construct(
        string                  $message,
        private readonly string $command,
        ?Throwable              $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getCommand(): string
    {
        return $this->command;
    }
}
