<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Infrastructure\Handler;

use App\WorkspaceMgmt\Domain\Entity\Workspace;
use App\WorkspaceMgmt\Domain\Service\WorkspaceSetupService;
use App\WorkspaceMgmt\Infrastructure\Message\SetupWorkspaceMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Handles async workspace setup.
 * The WorkspaceSetupService handles status transitions and error handling.
 */
#[AsMessageHandler]
final readonly class SetupWorkspaceHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private WorkspaceSetupService  $setupService,
        private LoggerInterface        $logger,
    ) {
    }

    public function __invoke(SetupWorkspaceMessage $message): void
    {
        $workspace = $this->entityManager->find(Workspace::class, $message->workspaceId);

        if ($workspace === null) {
            $this->logger->error('Workspace not found for async setup', [
                'workspaceId' => $message->workspaceId,
            ]);

            return;
        }

        try {
            $this->logger->info('Starting async workspace setup', [
                'workspaceId' => $message->workspaceId,
            ]);

            $this->setupService->setup($workspace, $message->userEmail);

            $this->logger->info('Async workspace setup completed', [
                'workspaceId' => $message->workspaceId,
            ]);
        } catch (Throwable $e) {
            // The setup service already sets status to PROBLEM on failure
            // Just log the error here for visibility
            $this->logger->error('Async workspace setup failed', [
                'workspaceId' => $message->workspaceId,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
