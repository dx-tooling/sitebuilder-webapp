<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Infrastructure\Handler;

use App\PhotoBuilder\Domain\Entity\PhotoSession;
use App\PhotoBuilder\Domain\Enum\PhotoSessionStatus;
use App\PhotoBuilder\Domain\Service\PhotoBuilderService;
use App\PhotoBuilder\Infrastructure\Adapter\PromptGeneratorInterface;
use App\PhotoBuilder\Infrastructure\Message\GenerateImageMessage;
use App\PhotoBuilder\Infrastructure\Message\GenerateImagePromptsMessage;
use App\ProjectMgmt\Facade\ProjectMgmtFacadeInterface;
use App\WorkspaceMgmt\Facade\WorkspaceMgmtFacadeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

#[AsMessageHandler]
final readonly class GenerateImagePromptsHandler
{
    public function __construct(
        private EntityManagerInterface       $entityManager,
        private PhotoBuilderService          $photoBuilderService,
        private PromptGeneratorInterface     $promptGenerator,
        private WorkspaceMgmtFacadeInterface $workspaceMgmtFacade,
        private ProjectMgmtFacadeInterface   $projectMgmtFacade,
        private MessageBusInterface          $messageBus,
        private LoggerInterface              $logger,
    ) {
    }

    public function __invoke(GenerateImagePromptsMessage $message): void
    {
        $session = $this->entityManager->find(PhotoSession::class, $message->sessionId);

        if ($session === null) {
            $this->logger->error('PhotoSession not found', ['sessionId' => $message->sessionId]);

            return;
        }

        try {
            // Load workspace and project info to get API key
            $workspace = $this->workspaceMgmtFacade->getWorkspaceById($session->getWorkspaceId());
            $project   = $workspace !== null ? $this->projectMgmtFacade->getProjectInfo($workspace->projectId) : null;

            if ($project === null || $project->llmApiKey === '') {
                $this->logger->error('No LLM API key configured for project', [
                    'sessionId'   => $message->sessionId,
                    'workspaceId' => $session->getWorkspaceId(),
                ]);

                $session->setStatus(PhotoSessionStatus::Failed);
                $this->entityManager->flush();

                return;
            }

            // Read page HTML from the dist/ directory
            $pagePath = 'dist/' . $session->getPagePath();
            $pageHtml = $this->workspaceMgmtFacade->readWorkspaceFile($session->getWorkspaceId(), $pagePath);

            // Generate prompts via LLM
            $promptResults = $this->promptGenerator->generatePrompts(
                $pageHtml,
                $session->getUserPrompt(),
                $project->llmApiKey,
                PhotoBuilderService::IMAGE_COUNT,
            );

            // Update image entities with generated prompts
            $this->photoBuilderService->updateImagePrompts($session, $promptResults);

            $session->setStatus(PhotoSessionStatus::PromptsReady);
            $this->entityManager->flush();

            // Dispatch image generation for each image
            $session->setStatus(PhotoSessionStatus::GeneratingImages);
            $this->entityManager->flush();

            foreach ($session->getImages() as $image) {
                if ($image->getPrompt() !== null && $image->getPrompt() !== '') {
                    $this->messageBus->dispatch(new GenerateImageMessage($image->getId()));
                }
            }
        } catch (Throwable $e) {
            $this->logger->error('Failed to generate image prompts', [
                'sessionId' => $message->sessionId,
                'error'     => $e->getMessage(),
            ]);

            $session->setStatus(PhotoSessionStatus::Failed);
            $this->entityManager->flush();
        }
    }
}
