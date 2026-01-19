<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Infrastructure\Service;

use App\WorkspaceTooling\Infrastructure\Service\Dto\FileInfoDto;

interface FileOperationsServiceInterface
{
    public function listFolderContent(string $pathToFolder): string;

    public function getFileContent(string $pathToFile): string;

    public function getFileLines(string $pathToFile, int $startLine, int $endLine): string;

    public function getFileInfo(string $pathToFile): FileInfoDto;

    public function searchInFile(string $pathToFile, string $searchPattern, int $contextLines = 3): string;

    public function replaceInFile(string $pathToFile, string $oldString, string $newString): string;

    public function writeFileContent(string $pathToFile, string $fileContent): void;
}
