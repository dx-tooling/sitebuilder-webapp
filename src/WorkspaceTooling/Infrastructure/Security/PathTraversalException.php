<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Infrastructure\Security;

use RuntimeException;

/**
 * Exception thrown when a path traversal attempt is detected.
 */
final class PathTraversalException extends RuntimeException
{
    public function __construct(
        string $targetPath,
        string $workspacePath
    ) {
        parent::__construct(sprintf(
            'Path traversal attempt detected: "%s" is outside workspace "%s"',
            $targetPath,
            $workspacePath
        ));
    }
}
