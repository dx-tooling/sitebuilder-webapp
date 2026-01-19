<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Infrastructure\Service;

interface FileOperationsServiceInterface
{
    public function listFolderContent(string $pathToFolder): string;

    public function getFileContent(string $pathToFile): string;

    public function writeFileContent(string $pathToFile, string $fileContent): void;
}
