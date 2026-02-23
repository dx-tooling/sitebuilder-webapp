<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Infrastructure\Handler;

use App\ChatBasedContentEditor\Domain\Entity\HtmlEditorBuild;
use App\ChatBasedContentEditor\Domain\Enum\HtmlEditorBuildStatus;
use App\ChatBasedContentEditor\Infrastructure\Message\RunHtmlBuildMessage;
use App\ProjectMgmt\Facade\ProjectMgmtFacadeInterface;
use App\WorkspaceMgmt\Facade\WorkspaceMgmtFacadeInterface;
use App\WorkspaceTooling\Facade\WorkspaceToolingServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler]
final readonly class RunHtmlBuildHandler
{
    public function __construct(
        private EntityManagerInterface           $entityManager,
        private WorkspaceMgmtFacadeInterface     $workspaceMgmtFacade,
        private ProjectMgmtFacadeInterface       $projectMgmtFacade,
        private WorkspaceToolingServiceInterface $workspaceToolingService,
        private LoggerInterface                  $logger,
    ) {
    }

    public function __invoke(RunHtmlBuildMessage $message): void
    {
        $build = $this->entityManager->find(HtmlEditorBuild::class, $message->buildId);

        if ($build === null) {
            $this->logger->error('HtmlEditorBuild not found', ['buildId' => $message->buildId]);

            return;
        }

        $build->setStatus(HtmlEditorBuildStatus::Running);
        $this->entityManager->flush();

        try {
            $workspace = $this->workspaceMgmtFacade->getWorkspaceById($build->getWorkspaceId());

            if ($workspace === null) {
                throw new RuntimeException('Workspace not found: ' . $build->getWorkspaceId());
            }

            $project = $this->projectMgmtFacade->getProjectInfo($workspace->projectId);

            $buildOutput = $this->workspaceToolingService->runBuildInWorkspace(
                $workspace->workspacePath,
                $project->agentImage
            );

            $this->logger->info('HTML editor Docker build completed', [
                'buildId'     => $message->buildId,
                'workspaceId' => $build->getWorkspaceId(),
                'sourcePath'  => $build->getSourcePath(),
                'buildOutput' => $buildOutput,
            ]);

            $this->workspaceMgmtFacade->commitAndPush(
                $build->getWorkspaceId(),
                'Manual HTML edit: ' . $build->getSourcePath(),
                $build->getUserEmail()
            );

            $build->setStatus(HtmlEditorBuildStatus::Completed);
            $this->entityManager->flush();
        } catch (Throwable $e) {
            $this->logger->error('HTML editor build failed', [
                'buildId'     => $message->buildId,
                'workspaceId' => $build->getWorkspaceId(),
                'sourcePath'  => $build->getSourcePath(),
                'error'       => $e->getMessage(),
            ]);

            $build->setStatus(HtmlEditorBuildStatus::Failed);
            $build->setErrorMessage($e->getMessage());
            $this->entityManager->flush();
        }
    }
}
