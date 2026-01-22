<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Infrastructure\SetupSteps;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Executes setup steps in a workspace directory.
 */
final class SetupStepsExecutor implements SetupStepsExecutorInterface
{
    private const int DEFAULT_TIMEOUT = 120; // 2 minutes

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Execute a list of setup steps in the given workspace path.
     *
     * @param list<SetupStep> $steps
     *
     * @throws RuntimeException if a step fails
     */
    public function execute(array $steps, string $workspacePath): void
    {
        foreach ($steps as $index => $step) {
            $this->executeStep($step, $workspacePath, $index + 1, count($steps));
        }
    }

    private function executeStep(SetupStep $step, string $workspacePath, int $current, int $total): void
    {
        $this->logger->info(
            sprintf('Executing setup step %d/%d: %s', $current, $total, $step->name),
            [
                'command'       => $step->getCommandLine(),
                'workspacePath' => $workspacePath,
            ]
        );

        $command = array_merge([$step->command], $step->arguments);
        $process = new Process($command);
        $process->setWorkingDirectory($workspacePath);
        $process->setTimeout($step->timeout ?? self::DEFAULT_TIMEOUT);

        try {
            $process->mustRun();

            $this->logger->debug(
                sprintf('Setup step completed: %s', $step->name),
                [
                    'exitCode' => $process->getExitCode(),
                    'output'   => $process->getOutput(),
                ]
            );
        } catch (ProcessFailedException $e) {
            $this->logger->error(
                sprintf('Setup step failed: %s', $step->name),
                [
                    'command'     => $step->getCommandLine(),
                    'exitCode'    => $process->getExitCode(),
                    'output'      => $process->getOutput(),
                    'errorOutput' => $process->getErrorOutput(),
                ]
            );

            throw new RuntimeException(
                sprintf(
                    'Setup step "%s" failed: %s',
                    $step->name,
                    $process->getErrorOutput() ?: $process->getOutput()
                ),
                0,
                $e
            );
        }
    }
}
