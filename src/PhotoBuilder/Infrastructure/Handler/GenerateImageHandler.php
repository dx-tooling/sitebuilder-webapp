<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Infrastructure\Handler;

use App\PhotoBuilder\Domain\Entity\PhotoImage;
use App\PhotoBuilder\Domain\Entity\PhotoSession;
use App\PhotoBuilder\Domain\Enum\PhotoImageStatus;
use App\PhotoBuilder\Domain\Service\PhotoBuilderService;
use App\PhotoBuilder\Infrastructure\Adapter\ImageGeneratorInterface;
use App\PhotoBuilder\Infrastructure\Message\GenerateImageMessage;
use App\PhotoBuilder\Infrastructure\Storage\GeneratedImageStorage;
use App\ProjectMgmt\Facade\ProjectMgmtFacadeInterface;
use App\WorkspaceMgmt\Facade\WorkspaceMgmtFacadeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler]
final readonly class GenerateImageHandler
{
    public function __construct(
        private EntityManagerInterface       $entityManager,
        private PhotoBuilderService          $photoBuilderService,
        private ImageGeneratorInterface      $imageGenerator,
        private GeneratedImageStorage        $imageStorage,
        private WorkspaceMgmtFacadeInterface $workspaceMgmtFacade,
        private ProjectMgmtFacadeInterface   $projectMgmtFacade,
        private LoggerInterface              $logger,
    ) {
    }

    public function __invoke(GenerateImageMessage $message): void
    {
        $image = $this->entityManager->find(PhotoImage::class, $message->imageId);

        if ($image === null) {
            $this->logger->error('PhotoImage not found', ['imageId' => $message->imageId]);

            return;
        }

        $session = $image->getSession();

        try {
            $image->setStatus(PhotoImageStatus::Generating);
            $this->entityManager->flush();

            // Get API key
            $workspace = $this->workspaceMgmtFacade->getWorkspaceById($session->getWorkspaceId());
            $project   = $workspace !== null ? $this->projectMgmtFacade->getProjectInfo($workspace->projectId) : null;

            if ($project === null || $project->llmApiKey === '') {
                $image->setStatus(PhotoImageStatus::Failed);
                $image->setErrorMessage('No LLM API key configured for project.');
                $this->entityManager->flush();
                $this->updateSessionStatus($session);

                return;
            }

            // Generate image
            $imageData = $this->imageGenerator->generateImage(
                $image->getPrompt() ?? '',
                $project->llmApiKey,
            );

            // Store on disk
            $storagePath = $this->imageStorage->save(
                $session->getId() ?? '',
                $image->getPosition(),
                $imageData,
            );

            $image->setStoragePath($storagePath);
            $image->setStatus(PhotoImageStatus::Completed);
            $this->entityManager->flush();
        } catch (Throwable $e) {
            $this->logger->error('Failed to generate image', [
                'imageId' => $message->imageId,
                'error'   => $e->getMessage(),
            ]);

            $image->setStatus(PhotoImageStatus::Failed);
            $image->setErrorMessage('Image generation failed: ' . $e->getMessage());
            $this->entityManager->flush();
        }

        $this->updateSessionStatus($session);
    }

    private function updateSessionStatus(PhotoSession $session): void
    {
        $this->photoBuilderService->updateSessionStatusFromImages($session);
    }
}
