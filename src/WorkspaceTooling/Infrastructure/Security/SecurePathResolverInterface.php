<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Infrastructure\Security;

/**
 * Interface for validating and resolving file paths within workspace boundaries.
 */
interface SecurePathResolverInterface
{
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
    public function resolve(string $workspacePath, string $targetPath): string;

    /**
     * Check if a path is within the workspace root.
     *
     * @param string $path The path to check
     *
     * @return bool True if path is under workspace root
     */
    public function isWithinWorkspaceRoot(string $path): bool;

    /**
     * Extract workspace ID from a workspace path.
     *
     * @param string $workspacePath Full path to workspace directory
     *
     * @return string|null The workspace ID (directory name) or null if invalid
     */
    public function extractWorkspaceId(string $workspacePath): ?string;
}
