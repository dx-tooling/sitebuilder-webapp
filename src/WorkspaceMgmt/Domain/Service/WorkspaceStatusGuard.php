<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Domain\Service;

use App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus;
use InvalidArgumentException;

/**
 * Guards workspace status transitions according to the workflow state table.
 */
final class WorkspaceStatusGuard implements WorkspaceStatusGuardInterface
{
    /**
     * Valid transitions: from_status => [list of valid to_statuses].
     *
     * @return array<int, list<int>>
     */
    private function getValidTransitions(): array
    {
        return [
            WorkspaceStatus::AVAILABLE_FOR_SETUP->value => [
                WorkspaceStatus::IN_SETUP->value,
            ],
            WorkspaceStatus::IN_SETUP->value => [
                WorkspaceStatus::AVAILABLE_FOR_CONVERSATION->value,
                WorkspaceStatus::PROBLEM->value,
            ],
            WorkspaceStatus::AVAILABLE_FOR_CONVERSATION->value => [
                WorkspaceStatus::IN_CONVERSATION->value,
            ],
            WorkspaceStatus::IN_CONVERSATION->value => [
                WorkspaceStatus::AVAILABLE_FOR_CONVERSATION->value,
                WorkspaceStatus::IN_REVIEW->value,
                WorkspaceStatus::PROBLEM->value,
            ],
            WorkspaceStatus::IN_REVIEW->value => [
                WorkspaceStatus::MERGED->value,
                WorkspaceStatus::AVAILABLE_FOR_CONVERSATION->value,
            ],
            WorkspaceStatus::MERGED->value => [
                WorkspaceStatus::IN_SETUP->value,
            ],
            WorkspaceStatus::PROBLEM->value => [
                WorkspaceStatus::AVAILABLE_FOR_SETUP->value,
            ],
        ];
    }

    public function validateTransition(WorkspaceStatus $from, WorkspaceStatus $to): void
    {
        $transitions  = $this->getValidTransitions();
        $validTargets = $transitions[$from->value];

        if (!in_array($to->value, $validTargets, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid workspace status transition from %s to %s',
                    $from->name,
                    $to->name
                )
            );
        }
    }

    public function isValidTransition(WorkspaceStatus $from, WorkspaceStatus $to): bool
    {
        $transitions  = $this->getValidTransitions();
        $validTargets = $transitions[$from->value];

        return in_array($to->value, $validTargets, true);
    }

    public function canStartConversation(WorkspaceStatus $status): bool
    {
        return $status === WorkspaceStatus::AVAILABLE_FOR_CONVERSATION
            || $status === WorkspaceStatus::AVAILABLE_FOR_SETUP
            || $status === WorkspaceStatus::MERGED;
    }

    public function needsSetup(WorkspaceStatus $status): bool
    {
        return $status === WorkspaceStatus::AVAILABLE_FOR_SETUP
            || $status === WorkspaceStatus::MERGED;
    }
}
