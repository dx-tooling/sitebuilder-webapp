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
use App\WorkspaceMgmt\Infrastructure\Adapter\FilesystemAdapterInterface;
use App\WorkspaceMgmt\Infrastructure\Message\SetupWorkspaceMessage;
use App\WorkspaceMgmt\Infrastructure\Service\GitHubUrlServiceInterface;
use App\WorkspaceTooling\Facade\WorkspaceToolingServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

use function str_starts_with;

/**
 * Facade implementation for workspace management.
 * Used by ChatBasedContentEditor for workspace lifecycle and git operations.
 */
final class WorkspaceMgmtFacade implements WorkspaceMgmtFacadeInterface
{
    public function __construct(
        #[Autowire(param: 'workspace_mgmt.workspace_root')]
        private readonly string                           $workspaceRoot,
        private readonly WorkspaceService                 $workspaceService,
        private readonly WorkspaceGitService              $gitService,
        private readonly WorkspaceStatusGuard             $statusGuard,
        private readonly ProjectMgmtFacadeInterface       $projectMgmtFacade,
        private readonly EntityManagerInterface           $entityManager,
        private readonly MessageBusInterface              $messageBus,
        private readonly GitHubUrlServiceInterface        $gitHubUrlService,
        private readonly FilesystemAdapterInterface       $filesystemAdapter,
        private readonly WorkspaceToolingServiceInterface $workspaceToolingService,
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

        // Clear branch and PR data since workspace will be re-setup
        $workspace->setBranchName(null);
        $workspace->setPullRequestUrl(null);

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
        }

        // Use cached PR URL from workspace entity (set when PR is created via ensurePullRequest)
        $githubPrUrl = $workspace->getPullRequestUrl();

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

    public function readWorkspaceFile(string $workspaceId, string $relativePath): string
    {
        $absolutePath = $this->resolveAndValidatePath($workspaceId, $relativePath);

        return $this->filesystemAdapter->readFile($absolutePath);
    }

    public function writeWorkspaceFile(string $workspaceId, string $relativePath, string $content): void
    {
        $absolutePath = $this->resolveAndValidatePath($workspaceId, $relativePath);
        $this->filesystemAdapter->writeFile($absolutePath, $content);
    }

    public function runBuild(string $workspaceId): string
    {
        $workspace = $this->getWorkspaceOrFail($workspaceId);

        // Get project info to determine the agent image
        $projectInfo = $this->projectMgmtFacade->getProjectInfo($workspace->getProjectId());

        $workspacePath = $this->workspaceRoot . '/' . $workspaceId;

        return $this->workspaceToolingService->runBuildInWorkspace($workspacePath, $projectInfo->agentImage);
    }

    /**
     * Resolve a relative path to an absolute path and validate it's within the workspace.
     */
    private function resolveAndValidatePath(string $workspaceId, string $relativePath): string
    {
        // Ensure workspace exists
        $this->getWorkspaceOrFail($workspaceId);

        $workspacePath = $this->workspaceRoot . '/' . $workspaceId;
        $absolutePath  = $workspacePath . '/' . $relativePath;

        // Resolve to real path to prevent path traversal
        $realWorkspacePath = realpath($workspacePath);
        $realDirPath       = realpath(dirname($absolutePath));

        if ($realWorkspacePath === false || $realDirPath === false) {
            throw new RuntimeException('Invalid path: workspace or directory does not exist');
        }

        $realAbsolutePath = $realDirPath . '/' . basename($absolutePath);

        // For existing files, use full realpath validation
        if ($this->filesystemAdapter->exists($absolutePath)) {
            $resolvedPath = realpath($absolutePath);
            if ($resolvedPath !== false) {
                $realAbsolutePath = $resolvedPath;
            }
        }

        if (!str_starts_with($realAbsolutePath, $realWorkspacePath)) {
            throw new RuntimeException('Invalid path: path traversal detected');
        }

        return $absolutePath;
    }
}
