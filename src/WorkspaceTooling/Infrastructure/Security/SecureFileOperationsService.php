<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Infrastructure\Security;

use App\WorkspaceTooling\Infrastructure\Execution\AgentExecutionContext;
use EtfsCodingAgent\Service\Dto\FileInfoDto;
use EtfsCodingAgent\Service\FileOperationsServiceInterface;

/**
 * Security decorator for file operations that validates all paths are within workspace boundaries.
 *
 * This service wraps the library's FileOperationsService to ensure that:
 * - All file paths are validated before any operation
 * - Path traversal attacks (../) are blocked
 * - Symlink escape attempts are detected and blocked
 * - /workspace paths are translated to actual workspace paths
 */
final class SecureFileOperationsService implements FileOperationsServiceInterface
{
    private const string WORKSPACE_ALIAS = '/workspace';

    public function __construct(
        private readonly FileOperationsServiceInterface $inner,
        private readonly SecurePathResolverInterface    $pathResolver,
        private readonly AgentExecutionContext          $executionContext,
        private readonly string                         $workspaceRoot
    ) {
    }

    public function listFolderContent(string $pathToFolder): string
    {
        $pathToFolder = $this->translatePath($pathToFolder);
        $this->validatePath($pathToFolder);

        return $this->translatePathBack($this->inner->listFolderContent($pathToFolder));
    }

    public function getFileContent(string $pathToFile): string
    {
        $pathToFile = $this->translatePath($pathToFile);
        $this->validatePath($pathToFile);

        return $this->translatePathBack($this->inner->getFileContent($pathToFile));
    }

    public function getFileLines(string $pathToFile, int $startLine, int $endLine): string
    {
        $pathToFile = $this->translatePath($pathToFile);
        $this->validatePath($pathToFile);

        return $this->translatePathBack($this->inner->getFileLines($pathToFile, $startLine, $endLine));
    }

    public function getFileInfo(string $pathToFile): FileInfoDto
    {
        $pathToFile = $this->translatePath($pathToFile);
        $this->validatePath($pathToFile);

        $dto = $this->inner->getFileInfo($pathToFile);

        // Return DTO with translated path
        return new FileInfoDto(
            $this->translatePathBack($dto->path),
            $dto->lineCount,
            $dto->sizeBytes,
            $dto->extension
        );
    }

    public function searchInFile(string $pathToFile, string $searchPattern, int $contextLines = 3): string
    {
        $pathToFile = $this->translatePath($pathToFile);
        $this->validatePath($pathToFile);

        return $this->translatePathBack($this->inner->searchInFile($pathToFile, $searchPattern, $contextLines));
    }

    public function replaceInFile(string $pathToFile, string $oldString, string $newString): string
    {
        $pathToFile = $this->translatePath($pathToFile);
        $this->validatePath($pathToFile);

        return $this->translatePathBack($this->inner->replaceInFile($pathToFile, $oldString, $newString));
    }

    public function writeFileContent(string $pathToFile, string $fileContent): void
    {
        $pathToFile = $this->translatePath($pathToFile);
        $this->validatePath($pathToFile);
        $this->inner->writeFileContent($pathToFile, $fileContent);
    }

    public function createDirectory(string $pathToDirectory): string
    {
        $pathToDirectory = $this->translatePath($pathToDirectory);
        $this->validatePath($pathToDirectory);

        return $this->translatePathBack($this->inner->createDirectory($pathToDirectory));
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

    /**
     * Translate /workspace paths to actual workspace paths.
     *
     * The agent sees /workspace as its working directory. This method translates
     * those paths to the actual filesystem path from the execution context.
     */
    private function translatePath(string $path): string
    {
        if (!str_starts_with($path, self::WORKSPACE_ALIAS)) {
            return $path;
        }

        $actualWorkspacePath = $this->executionContext->getWorkspacePath();

        if ($actualWorkspacePath === null) {
            // No context set - return path as-is (will fail validation)
            return $path;
        }

        // Replace /workspace with the actual path
        $relativePath = substr($path, strlen(self::WORKSPACE_ALIAS));

        return $actualWorkspacePath . $relativePath;
    }

    /**
     * Translate actual workspace paths back to /workspace paths in results.
     *
     * This ensures that messages returned to the agent show /workspace paths
     * (as the agent sees them) instead of the internal filesystem paths.
     */
    private function translatePathBack(string $result): string
    {
        $actualWorkspacePath = $this->executionContext->getWorkspacePath();

        if ($actualWorkspacePath === null) {
            return $result;
        }

        return str_replace($actualWorkspacePath, self::WORKSPACE_ALIAS, $result);
    }
}
