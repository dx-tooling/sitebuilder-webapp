<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Facade;

use EtfsCodingAgent\Facade\WorkspaceToolingFacade as BaseWorkspaceToolingFacade;
use EtfsCodingAgent\Service\FileOperationsServiceInterface;
use EtfsCodingAgent\Service\ShellOperationsServiceInterface;
use EtfsCodingAgent\Service\TextOperationsService;

final class WorkspaceToolingFacade extends BaseWorkspaceToolingFacade implements WorkspaceToolingFacadeInterface
{
    public function __construct(
        FileOperationsServiceInterface  $fileOperationsService,
        TextOperationsService           $textOperationsService,
        ShellOperationsServiceInterface $shellOperationsService
    ) {
        parent::__construct($fileOperationsService, $textOperationsService, $shellOperationsService);
    }

    public function runQualityChecks(string $pathToFolder): string
    {
        return $this->shellOperationsService->runCommand($pathToFolder, 'mise exec -- npm run quality');
    }

    public function runTests(string $pathToFolder): string
    {
        return $this->shellOperationsService->runCommand($pathToFolder, 'mise exec -- npm run test');
    }

    public function runBuild(string $pathToFolder): string
    {
        return $this->shellOperationsService->runCommand($pathToFolder, 'mise exec -- npm run build');
    }
}
