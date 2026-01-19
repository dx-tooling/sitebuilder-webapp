<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Infrastructure\Service;

interface ShellOperationsServiceInterface
{
    public function runCommand(string $workingDirectory, string $command): string;
}
