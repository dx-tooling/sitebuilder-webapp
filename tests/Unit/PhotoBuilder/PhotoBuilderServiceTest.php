<?php

declare(strict_types=1);

use App\PhotoBuilder\Domain\Entity\PhotoImage;
use App\PhotoBuilder\Domain\Entity\PhotoSession;
use App\PhotoBuilder\Domain\Enum\PhotoImageStatus;
use App\PhotoBuilder\Domain\Enum\PhotoSessionStatus;
use App\PhotoBuilder\Domain\Service\PhotoBuilderService;
use Doctrine\ORM\EntityManagerInterface;

describe('PhotoBuilderService', function (): void {
    describe('IMAGE_COUNT', function (): void {
        it('is defined as 5', function (): void {
            expect(PhotoBuilderService::IMAGE_COUNT)->toBe(5);
        });
    });

    describe('createSession', function (): void {
        it('creates a session with IMAGE_COUNT images', function (): void {
            $em = $this->createMock(EntityManagerInterface::class);
            $em->expects($this->once())->method('persist');
            $em->expects($this->once())->method('flush');

            $service = new PhotoBuilderService($em);
            $session = $service->createSession('ws-123', 'conv-456', 'index.html', 'Generate images');

            expect($session->getWorkspaceId())->toBe('ws-123')
                ->and($session->getConversationId())->toBe('conv-456')
                ->and($session->getPagePath())->toBe('index.html')
                ->and($session->getUserPrompt())->toBe('Generate images')
                ->and($session->getStatus())->toBe(PhotoSessionStatus::GeneratingPrompts)
                ->and($session->getImages())->toHaveCount(PhotoBuilderService::IMAGE_COUNT);

            // Verify positions are 0 through IMAGE_COUNT-1
            $positions = [];
            foreach ($session->getImages() as $image) {
                $positions[] = $image->getPosition();
                expect($image->getStatus())->toBe(PhotoImageStatus::Pending);
            }

            expect($positions)->toBe(range(0, PhotoBuilderService::IMAGE_COUNT - 1));
        });
    });

    describe('updateImagePrompts', function (): void {
        it('updates prompts for all images when no keep list provided', function (): void {
            $em      = $this->createMock(EntityManagerInterface::class);
            $service = new PhotoBuilderService($em);

            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');

            for ($i = 0; $i < 3; $i++) {
                new PhotoImage($session, $i);
            }

            $promptResults = [
                ['prompt' => 'Prompt A', 'fileName' => 'a.jpg'],
                ['prompt' => 'Prompt B', 'fileName' => 'b.jpg'],
                ['prompt' => 'Prompt C', 'fileName' => 'c.jpg'],
            ];

            $changed = $service->updateImagePrompts($session, $promptResults);

            expect($changed)->toHaveCount(3);

            $images = $session->getImages()->toArray();
            usort($images, static fn (PhotoImage $a, PhotoImage $b) => $a->getPosition() <=> $b->getPosition());

            expect($images[0]->getPrompt())->toBe('Prompt A')
                ->and($images[0]->getSuggestedFileName())->toBe('a.jpg')
                ->and($images[1]->getPrompt())->toBe('Prompt B')
                ->and($images[1]->getSuggestedFileName())->toBe('b.jpg')
                ->and($images[2]->getPrompt())->toBe('Prompt C')
                ->and($images[2]->getSuggestedFileName())->toBe('c.jpg');
        });

        it('skips images in the keep list', function (): void {
            $em      = $this->createMock(EntityManagerInterface::class);
            $service = new PhotoBuilderService($em);

            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');

            $images = [];
            for ($i = 0; $i < 3; $i++) {
                $images[] = new PhotoImage($session, $i);
            }

            // Pre-set image 1 with existing prompt
            $images[1]->setPrompt('Original prompt');
            $images[1]->setSuggestedFileName('original.jpg');

            // Use reflection to set IDs for the keep list
            $ref = new ReflectionClass(PhotoImage::class);
            $idProp = $ref->getProperty('id');
            $idProp->setValue($images[0], 'id-0');
            $idProp->setValue($images[1], 'id-1');
            $idProp->setValue($images[2], 'id-2');

            $promptResults = [
                ['prompt' => 'New A', 'fileName' => 'new-a.jpg'],
                ['prompt' => 'New B', 'fileName' => 'new-b.jpg'],
                ['prompt' => 'New C', 'fileName' => 'new-c.jpg'],
            ];

            $changed = $service->updateImagePrompts($session, $promptResults, ['id-1']);

            expect($changed)->toHaveCount(2);

            // Image 1 should keep its original prompt
            expect($images[1]->getPrompt())->toBe('Original prompt')
                ->and($images[1]->getSuggestedFileName())->toBe('original.jpg');

            // Images 0 and 2 should be updated
            expect($images[0]->getPrompt())->toBe('New A')
                ->and($images[2]->getPrompt())->toBe('New C');
        });

        it('resets image state when updating prompts', function (): void {
            $em      = $this->createMock(EntityManagerInterface::class);
            $service = new PhotoBuilderService($em);

            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image   = new PhotoImage($session, 0);

            // Simulate a previously completed image
            $image->setStatus(PhotoImageStatus::Completed);
            $image->setStoragePath('old/path.png');
            $image->setErrorMessage('old error');

            $promptResults = [
                ['prompt' => 'New prompt', 'fileName' => 'new.jpg'],
            ];

            $service->updateImagePrompts($session, $promptResults);

            expect($image->getStatus())->toBe(PhotoImageStatus::Pending)
                ->and($image->getStoragePath())->toBeNull()
                ->and($image->getErrorMessage())->toBeNull()
                ->and($image->getPrompt())->toBe('New prompt');
        });
    });

    describe('updateSessionStatusFromImages', function (): void {
        it('does nothing when not all images are terminal', function (): void {
            $em = $this->createMock(EntityManagerInterface::class);
            $em->expects($this->never())->method('flush');

            $service = new PhotoBuilderService($em);
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image0  = new PhotoImage($session, 0);
            $image1  = new PhotoImage($session, 1);

            $image0->setStatus(PhotoImageStatus::Completed);
            // image1 remains Pending

            $session->setStatus(PhotoSessionStatus::GeneratingImages);
            $service->updateSessionStatusFromImages($session);

            expect($session->getStatus())->toBe(PhotoSessionStatus::GeneratingImages);
        });

        it('sets ImagesReady when all images completed', function (): void {
            $em = $this->createMock(EntityManagerInterface::class);
            $em->expects($this->once())->method('flush');

            $service = new PhotoBuilderService($em);
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image0  = new PhotoImage($session, 0);
            $image1  = new PhotoImage($session, 1);

            $image0->setStatus(PhotoImageStatus::Completed);
            $image1->setStatus(PhotoImageStatus::Completed);

            $session->setStatus(PhotoSessionStatus::GeneratingImages);
            $service->updateSessionStatusFromImages($session);

            expect($session->getStatus())->toBe(PhotoSessionStatus::ImagesReady);
        });

        it('sets ImagesReady even when some images failed', function (): void {
            $em = $this->createMock(EntityManagerInterface::class);
            $em->expects($this->once())->method('flush');

            $service = new PhotoBuilderService($em);
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image0  = new PhotoImage($session, 0);
            $image1  = new PhotoImage($session, 1);

            $image0->setStatus(PhotoImageStatus::Completed);
            $image1->setStatus(PhotoImageStatus::Failed);

            $session->setStatus(PhotoSessionStatus::GeneratingImages);
            $service->updateSessionStatusFromImages($session);

            expect($session->getStatus())->toBe(PhotoSessionStatus::ImagesReady);
        });
    });
});
