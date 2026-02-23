<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Presentation\Dto;

/**
 * Internal context resolved when accessing an editable workspace for prompt suggestions.
 * Groups the workspace path, workspace ID, and author email needed for file operations and git commits.
 */
readonly class EditableWorkspaceContextDto
{
    public function __construct(
        public string $workspacePath,
        public string $workspaceId,
        public string $authorEmail,
    ) {
    }
}
