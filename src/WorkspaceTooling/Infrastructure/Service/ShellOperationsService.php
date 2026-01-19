<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Infrastructure\Service;

use RuntimeException;
use Symfony\Component\Process\Process;

final class ShellOperationsService implements ShellOperationsServiceInterface
{
    private const int DEFAULT_TIMEOUT = 300;

    public function runCommand(string $workingDirectory, string $command): string
    {
        if (!is_dir($workingDirectory)) {
            throw new RuntimeException("Directory does not exist: {$workingDirectory}");
        }

        $process = Process::fromShellCommandline($command);
        $process->setWorkingDirectory($workingDirectory);
        $process->setTimeout(self::DEFAULT_TIMEOUT);
        $process->setEnv([
            'MISE_YES' => '1',
        ]);

        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();

        if (!$process->isSuccessful()) {
            return "Command failed with exit code {$process->getExitCode()}:\n{$output}";
        }

        return $output;
    }
}
