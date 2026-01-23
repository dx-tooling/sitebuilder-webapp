<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Infrastructure\Security;

/**
 * Validates and resolves file paths to ensure they remain within workspace boundaries.
 *
 * This class provides security against path traversal attacks by:
 * - Canonicalizing paths (resolving ., .., and symlinks)
 * - Validating that resolved paths are under the workspace root
 * - Detecting symlink escape attempts
 */
final readonly class SecurePathResolver
{
    public function __construct(
        private string $workspaceRoot
    ) {
    }

    /**
     * Resolve and validate a path, ensuring it's within the given workspace.
     *
     * @param string $workspacePath The workspace directory (must be under workspace root)
     * @param string $targetPath    The path to validate (can be relative or absolute)
     *
     * @return string The canonicalized, validated path
     *
     * @throws PathTraversalException if the path is outside the workspace
     */
    public function resolve(string $workspacePath, string $targetPath): string
    {
        // First, validate that workspace itself is under workspace root
        $workspaceReal = $this->canonicalizePath($workspacePath);
        $this->validateUnderRoot($workspaceReal, 'Workspace');

        // Resolve target path
        $targetReal = $this->resolveTargetPath($workspacePath, $targetPath);

        // Verify target is under workspace
        if (!$this->isPathUnder($targetReal, $workspaceReal)) {
            throw new PathTraversalException($targetPath, $workspacePath);
        }

        return $targetReal;
    }

    /**
     * Check if a path is within the workspace root.
     *
     * @param string $path The path to check
     *
     * @return bool True if path is under workspace root
     */
    public function isWithinWorkspaceRoot(string $path): bool
    {
        try {
            $canonicalPath = $this->canonicalizePath($path);

            return $this->isPathUnder($canonicalPath, $this->workspaceRoot);
        } catch (PathTraversalException) {
            return false;
        }
    }

    /**
     * Extract workspace ID from a workspace path.
     *
     * @param string $workspacePath Full path to workspace directory
     *
     * @return string|null The workspace ID (directory name) or null if invalid
     */
    public function extractWorkspaceId(string $workspacePath): ?string
    {
        if (!$this->isWithinWorkspaceRoot($workspacePath)) {
            return null;
        }

        return basename($workspacePath);
    }

    /**
     * Canonicalize a path by resolving ., .., and following symlinks.
     *
     * @throws PathTraversalException if path cannot be resolved
     */
    private function canonicalizePath(string $path): string
    {
        $realPath = realpath($path);

        if ($realPath === false) {
            // Path doesn't exist - try to resolve parent and append basename
            $parent   = dirname($path);
            $basename = basename($path);

            $parentReal = realpath($parent);
            if ($parentReal === false) {
                throw new PathTraversalException($path, $this->workspaceRoot);
            }

            return $parentReal . DIRECTORY_SEPARATOR . $basename;
        }

        return $realPath;
    }

    /**
     * Resolve target path, handling both absolute and relative paths.
     */
    private function resolveTargetPath(string $workspacePath, string $targetPath): string
    {
        // If target is absolute, canonicalize directly
        if ($this->isAbsolutePath($targetPath)) {
            return $this->canonicalizePath($targetPath);
        }

        // Target is relative - resolve from workspace
        $fullPath = $workspacePath . DIRECTORY_SEPARATOR . $targetPath;

        return $this->canonicalizePath($fullPath);
    }

    /**
     * Check if one path is under another.
     */
    private function isPathUnder(string $path, string $root): bool
    {
        // Ensure root ends with separator for accurate prefix matching
        $normalizedRoot = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $normalizedPath = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        // Path must start with root or be exactly the root
        return str_starts_with($normalizedPath, $normalizedRoot)
               || rtrim($path, DIRECTORY_SEPARATOR) === rtrim($root, DIRECTORY_SEPARATOR);
    }

    /**
     * Check if a path is absolute.
     */
    private function isAbsolutePath(string $path): bool
    {
        // Unix absolute path
        if (str_starts_with($path, '/')) {
            return true;
        }

        // Windows absolute path (e.g., C:\)
        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Validate that a path is under the workspace root.
     *
     * @throws PathTraversalException if path is not under root
     */
    private function validateUnderRoot(string $path, string $context): void
    {
        if (!$this->isPathUnder($path, $this->workspaceRoot)) {
            throw new PathTraversalException(
                sprintf('%s path "%s"', $context, $path),
                $this->workspaceRoot
            );
        }
    }
}
