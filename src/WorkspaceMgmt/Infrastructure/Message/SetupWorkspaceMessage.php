<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Infrastructure\Message;

use EnterpriseToolingForSymfony\SharedBundle\WorkerSystem\SymfonyMessage\ImmediateSymfonyMessageInterface;

/**
 * Message to trigger async workspace setup.
 * Dispatched when a user starts working on a project that needs workspace setup.
 */
readonly class SetupWorkspaceMessage implements ImmediateSymfonyMessageInterface
{
    public function __construct(
        public string $workspaceId
    ) {
    }
}
