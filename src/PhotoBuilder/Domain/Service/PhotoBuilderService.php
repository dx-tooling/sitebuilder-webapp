<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Domain\Service;

use App\PhotoBuilder\Domain\Dto\ImagePromptResultDto;
use App\PhotoBuilder\Domain\Entity\PhotoImage;
use App\PhotoBuilder\Domain\Entity\PhotoSession;
use App\PhotoBuilder\Domain\Enum\PhotoImageStatus;
use App\PhotoBuilder\Domain\Enum\PhotoSessionStatus;
use Doctrine\ORM\EntityManagerInterface;

class PhotoBuilderService
{
    /**
     * The number of images generated per photo session.
     * Single source of truth — referenced by prompt generator, handlers, and frontend.
     */
    public const int IMAGE_COUNT = 2;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Create a new photo session with IMAGE_COUNT empty image slots.
     */
    public function createSession(
        string $workspaceId,
        string $conversationId,
        string $pagePath,
        string $userPrompt,
    ): PhotoSession {
        $session = new PhotoSession(
            $workspaceId,
            $conversationId,
            $pagePath,
            $userPrompt,
        );

        for ($i = 0; $i < self::IMAGE_COUNT; ++$i) {
            new PhotoImage($session, $i);
        }

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    /**
     * Update image prompts from LLM-generated results.
     *
     * @param list<ImagePromptResultDto> $promptResults
     * @param list<string>               $keepImageIds  Image IDs whose prompts should not be updated
     *
     * @return list<PhotoImage> Images whose prompts were actually changed
     */
    public function updateImagePrompts(
        PhotoSession $session,
        array        $promptResults,
        array        $keepImageIds = [],
    ): array {
        $changedImages = [];
        $images        = $session->getImages()->toArray();

        usort($images, static fn (PhotoImage $a, PhotoImage $b) => $a->getPosition() <=> $b->getPosition());

        foreach ($images as $index => $image) {
            if (in_array($image->getId(), $keepImageIds, true)) {
                continue;
            }

            if (!array_key_exists($index, $promptResults)) {
                continue;
            }

            $image->setPrompt($promptResults[$index]->prompt);
            $image->setSuggestedFileName($promptResults[$index]->fileName);
            $image->setStatus(PhotoImageStatus::Pending);
            $image->setStoragePath(null);
            $image->setErrorMessage(null);

            $changedImages[] = $image;
        }

        return $changedImages;
    }

    /**
     * Transition session status to images_ready if all images are in terminal state,
     * or to failed if any image failed and all are terminal.
     */
    public function updateSessionStatusFromImages(PhotoSession $session): void
    {
        if (!$session->areAllImagesTerminal()) {
            return;
        }

        if ($session->areAllImagesCompleted()) {
            $session->setStatus(PhotoSessionStatus::ImagesReady);
        } else {
            // At least one image failed, but all are terminal
            $session->setStatus(PhotoSessionStatus::ImagesReady);
        }

        $this->entityManager->flush();
    }
}
