<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Domain\Service;

use App\ProjectMgmt\Facade\Dto\ProjectInfoDto;
use App\ProjectMgmt\Facade\ProjectMgmtFacadeInterface;
use App\WorkspaceMgmt\Domain\Entity\Workspace;
use App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus;
use App\WorkspaceMgmt\Infrastructure\Adapter\FilesystemAdapterInterface;
use App\WorkspaceMgmt\Infrastructure\Adapter\GitAdapterInterface;
use App\WorkspaceMgmt\Infrastructure\SetupSteps\ProjectSetupStepsRegistryInterface;
use App\WorkspaceMgmt\Infrastructure\SetupSteps\SetupStepsExecutorInterface;
use Doctrine\ORM\EntityManagerInterface;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

use function mb_substr;

/**
 * Orchestrates workspace setup:
 * - Remove existing folder
 * - Clone repository
 * - Checkout new branch
 * - Run project-type-specific setup steps
 * - Update workspace status.
 */
final class WorkspaceSetupService
{
    public function __construct(
        #[Autowire(param: 'workspace_mgmt.workspace_root')]
        private readonly string                             $workspaceRoot,
        private readonly GitAdapterInterface                $gitAdapter,
        private readonly FilesystemAdapterInterface         $filesystemAdapter,
        private readonly ProjectMgmtFacadeInterface         $projectMgmtFacade,
        private readonly WorkspaceStatusGuardInterface      $statusGuard,
        private readonly ProjectSetupStepsRegistryInterface $setupStepsRegistry,
        private readonly SetupStepsExecutorInterface        $setupStepsExecutor,
        private readonly EntityManagerInterface             $entityManager,
        private readonly LoggerInterface                    $logger,
    ) {
    }

    /**
     * Set up a workspace for a conversation.
     * This transitions the workspace from AVAILABLE_FOR_SETUP/MERGED/IN_SETUP to AVAILABLE_FOR_CONVERSATION.
     *
     * Can be called when workspace is already IN_SETUP (async flow where facade
     * already transitioned the status before dispatching the setup message).
     */
    public function setup(Workspace $workspace): void
    {
        $workspaceId = $workspace->getId();
        if ($workspaceId === null) {
            throw new Exception('Workspace must be persisted before setup');
        }

        $currentStatus = $workspace->getStatus();

        // If already IN_SETUP (async flow), proceed directly to setup
        // Otherwise validate we can start setup and transition to IN_SETUP
        if ($currentStatus !== WorkspaceStatus::IN_SETUP) {
            if (!$this->statusGuard->needsSetup($currentStatus)) {
                throw new Exception(
                    sprintf('Cannot set up workspace in status %s', $currentStatus->name)
                );
            }

            // Transition to IN_SETUP
            $this->statusGuard->validateTransition($currentStatus, WorkspaceStatus::IN_SETUP);
            $workspace->setStatus(WorkspaceStatus::IN_SETUP);
            $this->entityManager->flush();
        }

        try {
            $this->performSetup($workspace);

            // Transition to AVAILABLE_FOR_CONVERSATION
            $workspace->setStatus(WorkspaceStatus::AVAILABLE_FOR_CONVERSATION);
            $this->entityManager->flush();

            $this->logger->info('Workspace setup completed', [
                'workspaceId' => $workspaceId,
                'branchName'  => $workspace->getBranchName(),
            ]);
        } catch (Throwable $e) {
            // On any failure, set to PROBLEM
            $workspace->setStatus(WorkspaceStatus::PROBLEM);
            $this->entityManager->flush();

            $this->logger->error('Workspace setup failed', [
                'workspaceId' => $workspaceId,
                'error'       => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get the workspace path for a workspace.
     */
    public function getWorkspacePath(Workspace $workspace): string
    {
        return $this->workspaceRoot . '/' . $workspace->getId();
    }

    private function performSetup(Workspace $workspace): void
    {
        $workspaceId   = $workspace->getId();
        $workspacePath = $this->getWorkspacePath($workspace);

        if ($workspaceId === null) {
            throw new Exception('Workspace ID cannot be null during setup');
        }

        // Get project info for git URL and token
        $projectInfo = $this->projectMgmtFacade->getProjectInfo($workspace->getProjectId());

        // Step 1: Remove existing folder
        $this->logger->debug('Removing workspace folder', ['path' => $workspacePath]);
        $this->filesystemAdapter->removeDirectory($workspacePath);

        // Step 2: Clone repository
        $this->logger->debug('Cloning repository', [
            'gitUrl' => $projectInfo->gitUrl,
            'path'   => $workspacePath,
        ]);
        $this->gitAdapter->clone($projectInfo->gitUrl, $workspacePath, $projectInfo->githubToken);

        // Step 3: Generate branch name and checkout
        $branchName = $this->generateBranchName($workspaceId);
        $this->logger->debug('Creating branch', ['branchName' => $branchName]);
        $this->gitAdapter->checkoutNewBranch($workspacePath, $branchName);

        // Update workspace with branch name
        $workspace->setBranchName($branchName);

        // Step 4: Run project-type-specific setup steps
        $this->runProjectSetupSteps($projectInfo, $workspacePath);
    }

    private function runProjectSetupSteps(ProjectInfoDto $projectInfo, string $workspacePath): void
    {
        $setupSteps = $this->setupStepsRegistry->getSetupSteps($projectInfo->projectType);

        if ($setupSteps === []) {
            $this->logger->debug('No setup steps defined for project type', [
                'projectType' => $projectInfo->projectType->value,
            ]);

            return;
        }

        $this->logger->info('Running project setup steps', [
            'projectType' => $projectInfo->projectType->value,
            'stepCount'   => count($setupSteps),
        ]);

        $this->setupStepsExecutor->execute($setupSteps, $workspacePath);

        $this->logger->info('Project setup steps completed', [
            'projectType' => $projectInfo->projectType->value,
        ]);
    }

    private function generateBranchName(string $workspaceId): string
    {
        $shortId   = mb_substr($workspaceId, 0, 8);
        $timestamp = DateAndTimeService::getDateTimeImmutable()->format('Ymd-His');

        return 'ws-' . $shortId . '-' . $timestamp;
    }
}
