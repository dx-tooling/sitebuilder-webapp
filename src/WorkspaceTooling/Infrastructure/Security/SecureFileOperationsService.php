<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Infrastructure\Security;

use EtfsCodingAgent\Service\Dto\FileInfoDto;
use EtfsCodingAgent\Service\FileOperationsServiceInterface;

/**
 * Security decorator for file operations that validates all paths are within workspace boundaries.
 *
 * This service wraps the library's FileOperationsService to ensure that:
 * - All file paths are validated before any operation
 * - Path traversal attacks (../) are blocked
 * - Symlink escape attempts are detected and blocked
 */
final class SecureFileOperationsService implements FileOperationsServiceInterface
{
    public function __construct(
        private readonly FileOperationsServiceInterface $inner,
        private readonly SecurePathResolver             $pathResolver,
        private readonly string                         $workspaceRoot
    ) {
    }

    public function listFolderContent(string $pathToFolder): string
    {
        $this->validatePath($pathToFolder);

        return $this->inner->listFolderContent($pathToFolder);
    }

    public function getFileContent(string $pathToFile): string
    {
        $this->validatePath($pathToFile);

        return $this->inner->getFileContent($pathToFile);
    }

    public function getFileLines(string $pathToFile, int $startLine, int $endLine): string
    {
        $this->validatePath($pathToFile);

        return $this->inner->getFileLines($pathToFile, $startLine, $endLine);
    }

    public function getFileInfo(string $pathToFile): FileInfoDto
    {
        $this->validatePath($pathToFile);

        return $this->inner->getFileInfo($pathToFile);
    }

    public function searchInFile(string $pathToFile, string $searchPattern, int $contextLines = 3): string
    {
        $this->validatePath($pathToFile);

        return $this->inner->searchInFile($pathToFile, $searchPattern, $contextLines);
    }

    public function replaceInFile(string $pathToFile, string $oldString, string $newString): string
    {
        $this->validatePath($pathToFile);

        return $this->inner->replaceInFile($pathToFile, $oldString, $newString);
    }

    public function writeFileContent(string $pathToFile, string $fileContent): void
    {
        $this->validatePath($pathToFile);
        $this->inner->writeFileContent($pathToFile, $fileContent);
    }

    public function createDirectory(string $pathToDirectory): string
    {
        $this->validatePath($pathToDirectory);

        return $this->inner->createDirectory($pathToDirectory);
    }

    /**
     * Validate that a path is within the workspace root.
     *
     * @throws PathTraversalException if path is outside workspace root
     */
    private function validatePath(string $path): void
    {
        if (!$this->pathResolver->isWithinWorkspaceRoot($path)) {
            throw new PathTraversalException($path, $this->workspaceRoot);
        }
    }
}
