<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Facade;

use App\ProjectMgmt\Facade\ProjectMgmtFacadeInterface;
use App\WorkspaceMgmt\Domain\Entity\Workspace;
use App\WorkspaceMgmt\Domain\Service\WorkspaceGitService;
use App\WorkspaceMgmt\Domain\Service\WorkspaceService;
use App\WorkspaceMgmt\Domain\Service\WorkspaceSetupService;
use App\WorkspaceMgmt\Domain\Service\WorkspaceStatusGuard;
use App\WorkspaceMgmt\Facade\Dto\WorkspaceInfoDto;
use App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Facade implementation for workspace management.
 * Used by ChatBasedContentEditor for workspace lifecycle and git operations.
 */
final class WorkspaceMgmtFacade implements WorkspaceMgmtFacadeInterface
{
    public function __construct(
        #[Autowire(param: 'workspace_mgmt.workspace_root')]
        private readonly string                     $workspaceRoot,
        private readonly WorkspaceService           $workspaceService,
        private readonly WorkspaceSetupService      $setupService,
        private readonly WorkspaceGitService        $gitService,
        private readonly WorkspaceStatusGuard       $statusGuard,
        private readonly ProjectMgmtFacadeInterface $projectMgmtFacade,
        private readonly EntityManagerInterface     $entityManager,
    ) {
    }

    public function getWorkspaceById(string $workspaceId): ?WorkspaceInfoDto
    {
        $workspace = $this->workspaceService->findById($workspaceId);

        if ($workspace === null) {
            return null;
        }

        return $this->toDto($workspace);
    }

    public function getWorkspaceForProject(string $projectId): ?WorkspaceInfoDto
    {
        $workspace = $this->workspaceService->findByProjectId($projectId);

        if ($workspace === null) {
            return null;
        }

        return $this->toDto($workspace);
    }

    public function ensureWorkspaceReadyForConversation(string $projectId): WorkspaceInfoDto
    {
        // Use pessimistic locking to prevent race conditions
        $this->entityManager->beginTransaction();

        try {
            // Find or create workspace
            $workspace = $this->workspaceService->findByProjectId($projectId);

            if ($workspace === null) {
                $workspace = $this->workspaceService->create($projectId);
            }

            $status = $workspace->getStatus();

            // If needs setup, run setup
            if ($this->statusGuard->needsSetup($status)) {
                $this->setupService->setup($workspace);
            }

            // Verify workspace is now available for conversation
            $currentStatus = $workspace->getStatus();
            if ($currentStatus !== WorkspaceStatus::AVAILABLE_FOR_CONVERSATION) {
                throw new RuntimeException(
                    sprintf(
                        'Workspace is not available for conversation. Current status: %s',
                        $currentStatus->name
                    )
                );
            }

            $this->entityManager->commit();

            return $this->toDto($workspace);
        } catch (Throwable $e) {
            $this->entityManager->rollback();

            throw $e;
        }
    }

    public function transitionToInConversation(string $workspaceId): void
    {
        $workspace = $this->getWorkspaceOrFail($workspaceId);
        $this->workspaceService->transitionTo($workspace, WorkspaceStatus::IN_CONVERSATION);
    }

    public function transitionToAvailableForConversation(string $workspaceId): void
    {
        $workspace = $this->getWorkspaceOrFail($workspaceId);
        $this->workspaceService->transitionTo($workspace, WorkspaceStatus::AVAILABLE_FOR_CONVERSATION);
    }

    public function transitionToInReview(string $workspaceId): void
    {
        $workspace = $this->getWorkspaceOrFail($workspaceId);
        $this->workspaceService->transitionTo($workspace, WorkspaceStatus::IN_REVIEW);
    }

    public function resetProblemWorkspace(string $workspaceId): void
    {
        $workspace = $this->getWorkspaceOrFail($workspaceId);

        if ($workspace->getStatus() !== WorkspaceStatus::PROBLEM) {
            throw new RuntimeException('Can only reset workspaces in PROBLEM status');
        }

        $this->workspaceService->transitionTo($workspace, WorkspaceStatus::AVAILABLE_FOR_SETUP);
    }

    public function resetWorkspaceForSetup(string $workspaceId): void
    {
        $workspace = $this->getWorkspaceOrFail($workspaceId);

        // Use setStatus to bypass transition validation since we're doing a reset
        $this->workspaceService->setStatus($workspace, WorkspaceStatus::AVAILABLE_FOR_SETUP);
    }

    public function commitAndPush(string $workspaceId, string $message, string $authorEmail): void
    {
        $workspace = $this->getWorkspaceOrFail($workspaceId);

        try {
            $this->gitService->commitAndPush($workspace, $message, $authorEmail);
        } catch (Throwable $e) {
            // On git failure, set workspace to PROBLEM
            $this->workspaceService->setStatus($workspace, WorkspaceStatus::PROBLEM);

            throw $e;
        }
    }

    public function ensurePullRequest(string $workspaceId): string
    {
        $workspace = $this->getWorkspaceOrFail($workspaceId);

        return $this->gitService->ensurePullRequest($workspace);
    }

    private function getWorkspaceOrFail(string $workspaceId): Workspace
    {
        $workspace = $this->workspaceService->findById($workspaceId);

        if ($workspace === null) {
            throw new RuntimeException('Workspace not found: ' . $workspaceId);
        }

        return $workspace;
    }

    private function toDto(Workspace $workspace): WorkspaceInfoDto
    {
        $id = $workspace->getId();
        if ($id === null) {
            throw new RuntimeException('Workspace ID cannot be null');
        }

        $projectInfo = $this->projectMgmtFacade->getProjectInfo($workspace->getProjectId());

        return new WorkspaceInfoDto(
            $id,
            $workspace->getProjectId(),
            $projectInfo->name,
            $workspace->getStatus(),
            $workspace->getBranchName(),
            $this->workspaceRoot . '/' . $id,
        );
    }
}
