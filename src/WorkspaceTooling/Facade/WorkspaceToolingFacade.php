<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Facade;

use EtfsCodingAgent\Service\FileOperationsServiceInterface;
use EtfsCodingAgent\Service\ShellOperationsServiceInterface;
use EtfsCodingAgent\Service\TextOperationsService;
use EtfsCodingAgent\Service\WorkspaceToolingService as BaseWorkspaceToolingFacade;

final class WorkspaceToolingFacade extends BaseWorkspaceToolingFacade implements WorkspaceToolingServiceInterface
{
    public function __construct(
        FileOperationsServiceInterface  $fileOperationsService,
        TextOperationsService           $textOperationsService,
        ShellOperationsServiceInterface $shellOperationsService
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
            'mise exec -- npm run quality'
        );
    }

    public function runTests(string $pathToFolder): string
    {
        return $this->shellOperationsService->runCommand(
            $pathToFolder,
            'mise exec -- npm run test'
        );
    }

    public function runBuild(string $pathToFolder): string
    {
        return $this->shellOperationsService->runCommand(
            $pathToFolder,
            'mise exec -- npm run build'
        );
    }

    public function applyV4aDiffToFile(string $pathToFile, string $v4aDiff): string
    {
        $modifiedContent = $this->textOperationsService->applyDiffToFile($pathToFile, $v4aDiff);
        $this->fileOperationsService->writeFileContent($pathToFile, $modifiedContent);
        $lineCount = substr_count($modifiedContent, "\n") + 1;

        return "Applied. File now has {$lineCount} lines.";
    }
}
