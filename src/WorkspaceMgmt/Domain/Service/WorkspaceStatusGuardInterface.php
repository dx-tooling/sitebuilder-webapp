<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Domain\Service;

use App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus;
use InvalidArgumentException;

/**
 * Interface for workspace status transition guard.
 */
interface WorkspaceStatusGuardInterface
{
    /**
     * Validate that a transition from one status to another is allowed.
     *
     * @throws InvalidArgumentException if the transition is not allowed
     */
    public function validateTransition(WorkspaceStatus $from, WorkspaceStatus $to): void;

    /**
     * Check if a transition is valid without throwing an exception.
     */
    public function isValidTransition(WorkspaceStatus $from, WorkspaceStatus $to): bool;

    /**
     * Check if a workspace is available for starting a conversation.
     */
    public function canStartConversation(WorkspaceStatus $status): bool;

    /**
     * Check if a workspace needs setup before a conversation can start.
     */
    public function needsSetup(WorkspaceStatus $status): bool;
}
