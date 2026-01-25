<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Facade;

use App\ProjectMgmt\Facade\ProjectMgmtFacadeInterface;
use App\WorkspaceMgmt\Domain\Entity\Workspace;
use App\WorkspaceMgmt\Domain\Service\WorkspaceGitService;
use App\WorkspaceMgmt\Domain\Service\WorkspaceService;
use App\WorkspaceMgmt\Domain\Service\WorkspaceStatusGuard;
use App\WorkspaceMgmt\Facade\Dto\WorkspaceInfoDto;
use App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus;
use App\WorkspaceMgmt\Infrastructure\Message\SetupWorkspaceMessage;
use App\WorkspaceMgmt\Infrastructure\Service\GitHubUrlServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
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
        private readonly WorkspaceGitService        $gitService,
        private readonly WorkspaceStatusGuard       $statusGuard,
        private readonly ProjectMgmtFacadeInterface $projectMgmtFacade,
        private readonly EntityManagerInterface     $entityManager,
        private readonly MessageBusInterface        $messageBus,
        private readonly GitHubUrlServiceInterface  $gitHubUrlService,
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

    public function dispatchSetupIfNeeded(string $projectId): WorkspaceInfoDto
    {
        // Use transaction to prevent race conditions when creating workspace
        $this->entityManager->beginTransaction();

        try {
            // Find or create workspace
            $workspace = $this->workspaceService->findByProjectId($projectId);

            if ($workspace === null) {
                $workspace = $this->workspaceService->create($projectId);
            }

            $workspaceId = $workspace->getId();
            if ($workspaceId === null) {
                throw new RuntimeException('Workspace ID cannot be null');
            }

            $status = $workspace->getStatus();

            // If workspace needs setup and is not already in setup, start async setup
            if ($this->statusGuard->needsSetup($status)) {
                // Transition to IN_SETUP to prevent duplicate setup attempts
                $this->statusGuard->validateTransition($status, WorkspaceStatus::IN_SETUP);
                $workspace->setStatus(WorkspaceStatus::IN_SETUP);
                $this->entityManager->flush();

                // Dispatch async setup message
                $this->messageBus->dispatch(new SetupWorkspaceMessage($workspaceId));
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

    public function deleteWorkspace(string $workspaceId): void
    {
        $workspace = $this->getWorkspaceOrFail($workspaceId);
        $this->workspaceService->delete($workspace);
    }

    public function commitAndPush(
        string  $workspaceId,
        string  $message,
        string  $authorEmail,
        ?string $conversationId = null,
        ?string $conversationUrl = null
    ): void {
        $workspace = $this->getWorkspaceOrFail($workspaceId);

        try {
            $this->gitService->commitAndPush($workspace, $message, $authorEmail, $conversationId, $conversationUrl);
        } catch (Throwable $e) {
            // On git failure, set workspace to PROBLEM
            $this->workspaceService->setStatus($workspace, WorkspaceStatus::PROBLEM);

            throw $e;
        }
    }

    public function ensurePullRequest(
        string  $workspaceId,
        ?string $conversationId = null,
        ?string $conversationUrl = null,
        ?string $userEmail = null
    ): string {
        $workspace = $this->getWorkspaceOrFail($workspaceId);

        return $this->gitService->ensurePullRequest($workspace, $conversationId, $conversationUrl, $userEmail);
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

        $branchName      = $workspace->getBranchName();
        $githubBranchUrl = null;
        $githubPrUrl     = null;

        if ($branchName !== null) {
            $githubBranchUrl = $this->gitHubUrlService->getBranchUrl($projectInfo->gitUrl, $branchName);

            // Try to find existing PR URL (this is best-effort, may be null if PR doesn't exist yet)
            try {
                $githubPrUrl = $this->gitService->findPullRequestUrl($workspace);
            } catch (Throwable) {
                // PR might not exist yet, that's okay
                $githubPrUrl = null;
            }
        }

        return new WorkspaceInfoDto(
            $id,
            $workspace->getProjectId(),
            $projectInfo->name,
            $workspace->getStatus(),
            $branchName,
            $this->workspaceRoot . '/' . $id,
            $githubBranchUrl,
            $githubPrUrl,
        );
    }
}
