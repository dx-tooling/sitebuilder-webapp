<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Facade;

use App\WorkspaceTooling\Domain\Service\TextOperationsService;
use App\WorkspaceTooling\Infrastructure\Service\FileOperationsServiceInterface;
use App\WorkspaceTooling\Infrastructure\Service\ShellOperationsServiceInterface;

final readonly class WorkspaceToolingFacade implements WorkspaceToolingFacadeInterface
{
    public function __construct(
        private FileOperationsServiceInterface  $fileOperationsService,
        private TextOperationsService           $textOperationsService,
        private ShellOperationsServiceInterface $shellOperationsService
    ) {
    }

    public function getFolderContent(string $pathToFolder): string
    {
        return $this->fileOperationsService->listFolderContent($pathToFolder);
    }

    public function getFileContent(string $pathToFile): string
    {
        return $this->fileOperationsService->getFileContent($pathToFile);
    }

    public function getFileLines(string $pathToFile, int $startLine, int $endLine): string
    {
        return $this->fileOperationsService->getFileLines($pathToFile, $startLine, $endLine);
    }

    public function getFileInfo(string $pathToFile): string
    {
        return $this->fileOperationsService->getFileInfo($pathToFile)->toString();
    }

    public function searchInFile(string $pathToFile, string $searchPattern, int $contextLines = 3): string
    {
        return $this->fileOperationsService->searchInFile($pathToFile, $searchPattern, $contextLines);
    }

    public function replaceInFile(string $pathToFile, string $oldString, string $newString): string
    {
        return $this->fileOperationsService->replaceInFile($pathToFile, $oldString, $newString);
    }

    public function applyV4aDiffToFile(string $pathToFile, string $v4aDiff): string
    {
        $modifiedContent = $this->textOperationsService->applyDiffToFile($pathToFile, $v4aDiff);
        $this->fileOperationsService->writeFileContent($pathToFile, $modifiedContent);

        return $modifiedContent;
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
