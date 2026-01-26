<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Facade;

use App\WorkspaceTooling\Infrastructure\Execution\AgentExecutionContext;
use EtfsCodingAgent\Service\FileOperationsServiceInterface;
use EtfsCodingAgent\Service\ShellOperationsServiceInterface;
use EtfsCodingAgent\Service\TextOperationsService;
use EtfsCodingAgent\Service\WorkspaceToolingService as BaseWorkspaceToolingFacade;

final class WorkspaceToolingFacade extends BaseWorkspaceToolingFacade implements WorkspaceToolingServiceInterface
{
    public function __construct(
        FileOperationsServiceInterface         $fileOperationsService,
        TextOperationsService                  $textOperationsService,
        ShellOperationsServiceInterface        $shellOperationsService,
        private readonly AgentExecutionContext $executionContext
    ) {
        parent::__construct(
            $fileOperationsService,
            $textOperationsService,
            $shellOperationsService
        );
    }

    public function runQualityChecks(string $pathToFolder): string
    {
        return $this->shellOperationsService->runCommand(
            $pathToFolder,
            'npm run quality'
        );
    }

    public function runTests(string $pathToFolder): string
    {
        return $this->shellOperationsService->runCommand(
            $pathToFolder,
            'npm run test'
        );
    }

    public function runBuild(string $pathToFolder): string
    {
        return $this->shellOperationsService->runCommand(
            $pathToFolder,
            'npm run build'
        );
    }

    public function applyV4aDiffToFile(string $pathToFile, string $v4aDiff): string
    {
        $modifiedContent = $this->textOperationsService->applyDiffToFile($pathToFile, $v4aDiff);
        $this->fileOperationsService->writeFileContent($pathToFile, $modifiedContent);
        $lineCount = substr_count($modifiedContent, "\n") + 1;

        return "Applied. File now has {$lineCount} lines.";
    }

    public function suggestCommitMessage(string $message): string
    {
        $this->executionContext->setSuggestedCommitMessage($message);

        return 'Commit message recorded: ' . $message;
    }
}
