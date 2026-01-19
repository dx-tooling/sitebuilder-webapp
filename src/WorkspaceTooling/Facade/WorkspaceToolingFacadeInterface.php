<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Facade;

interface WorkspaceToolingFacadeInterface
{
    public function getFolderContent(string $pathToFolder): string;

    public function getFileContent(string $pathToFile): string;

    public function getFileLines(string $pathToFile, int $startLine, int $endLine): string;

    public function getFileInfo(string $pathToFile): string;

    public function searchInFile(string $pathToFile, string $searchPattern, int $contextLines = 3): string;

    public function replaceInFile(string $pathToFile, string $oldString, string $newString): string;

    public function applyV4aDiffToFile(string $pathToFile, string $v4aDiff): string;

    public function runQualityChecks(string $pathToFolder): string;

    public function runTests(string $pathToFolder): string;

    public function runBuild(string $pathToFolder): string;

    public function createDirectory(string $pathToDirectory): string;
}
