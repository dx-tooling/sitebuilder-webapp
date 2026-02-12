<?php

declare(strict_types=1);

namespace Tests\Unit\PhotoBuilder;

use App\PhotoBuilder\Domain\Dto\ImagePromptResultDto;
use App\PhotoBuilder\Domain\Entity\PhotoImage;
use App\PhotoBuilder\Domain\Entity\PhotoSession;
use App\PhotoBuilder\Domain\Enum\PhotoImageStatus;
use App\PhotoBuilder\Domain\Enum\PhotoSessionStatus;
use App\PhotoBuilder\Domain\Service\PhotoBuilderService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PhotoBuilderServiceTest extends TestCase
{
    public function testImageCountIsPositiveInteger(): void
    {
        self::assertGreaterThan(0, PhotoBuilderService::IMAGE_COUNT);
    }

    public function testCreateSessionCreatesSessionWithImageCountImages(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $service = new PhotoBuilderService($em);
        $session = $service->createSession('ws-123', 'conv-456', 'index.html', 'Generate images');

        self::assertSame('ws-123', $session->getWorkspaceId());
        self::assertSame('conv-456', $session->getConversationId());
        self::assertSame('index.html', $session->getPagePath());
        self::assertSame('Generate images', $session->getUserPrompt());
        self::assertSame(PhotoSessionStatus::GeneratingPrompts, $session->getStatus());
        self::assertCount(PhotoBuilderService::IMAGE_COUNT, $session->getImages());

        // Verify positions are 0 through IMAGE_COUNT-1
        $positions = [];
        foreach ($session->getImages() as $image) {
            $positions[] = $image->getPosition();
            self::assertSame(PhotoImageStatus::Pending, $image->getStatus());
        }

        self::assertSame(range(0, PhotoBuilderService::IMAGE_COUNT - 1), $positions);
    }

    public function testUpdateImagePromptsUpdatesAllImagesWhenNoKeepList(): void
    {
        $em      = $this->createMock(EntityManagerInterface::class);
        $service = new PhotoBuilderService($em);

        $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');

        for ($i = 0; $i < 3; ++$i) {
            new PhotoImage($session, $i);
        }

        $promptResults = [
            new ImagePromptResultDto('Prompt A', 'a.jpg'),
            new ImagePromptResultDto('Prompt B', 'b.jpg'),
            new ImagePromptResultDto('Prompt C', 'c.jpg'),
        ];

        $changed = $service->updateImagePrompts($session, $promptResults);

        self::assertCount(3, $changed);

        $images = $session->getImages()->toArray();
        usort($images, static fn (PhotoImage $a, PhotoImage $b) => $a->getPosition() <=> $b->getPosition());

        self::assertSame('Prompt A', $images[0]->getPrompt());
        self::assertSame('a.jpg', $images[0]->getSuggestedFileName());
        self::assertSame('Prompt B', $images[1]->getPrompt());
        self::assertSame('b.jpg', $images[1]->getSuggestedFileName());
        self::assertSame('Prompt C', $images[2]->getPrompt());
        self::assertSame('c.jpg', $images[2]->getSuggestedFileName());
    }

    public function testUpdateImagePromptsSkipsImagesInKeepList(): void
    {
        $em      = $this->createMock(EntityManagerInterface::class);
        $service = new PhotoBuilderService($em);

        $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');

        $images = [];
        for ($i = 0; $i < 3; ++$i) {
            $images[] = new PhotoImage($session, $i);
        }

        // Pre-set image 1 with existing prompt
        $images[1]->setPrompt('Original prompt');
        $images[1]->setSuggestedFileName('original.jpg');

        // Use reflection to set IDs for the keep list
        $ref    = new ReflectionClass(PhotoImage::class);
        $idProp = $ref->getProperty('id');
        $idProp->setValue($images[0], 'id-0');
        $idProp->setValue($images[1], 'id-1');
        $idProp->setValue($images[2], 'id-2');

        $promptResults = [
            new ImagePromptResultDto('New A', 'new-a.jpg'),
            new ImagePromptResultDto('New B', 'new-b.jpg'),
            new ImagePromptResultDto('New C', 'new-c.jpg'),
        ];

        $changed = $service->updateImagePrompts($session, $promptResults, ['id-1']);

        self::assertCount(2, $changed);

        // Image 1 should keep its original prompt
        self::assertSame('Original prompt', $images[1]->getPrompt());
        self::assertSame('original.jpg', $images[1]->getSuggestedFileName());

        // Images 0 and 2 should be updated
        self::assertSame('New A', $images[0]->getPrompt());
        self::assertSame('New C', $images[2]->getPrompt());
    }

    public function testUpdateImagePromptsResetsImageState(): void
    {
        $em      = $this->createMock(EntityManagerInterface::class);
        $service = new PhotoBuilderService($em);

        $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
        $image   = new PhotoImage($session, 0);

        // Simulate a previously completed image
        $image->setStatus(PhotoImageStatus::Completed);
        $image->setStoragePath('old/path.png');
        $image->setErrorMessage('old error');

        $promptResults = [
            new ImagePromptResultDto('New prompt', 'new.jpg'),
        ];

        $service->updateImagePrompts($session, $promptResults);

        self::assertSame(PhotoImageStatus::Pending, $image->getStatus());
        self::assertNull($image->getStoragePath());
        self::assertNull($image->getErrorMessage());
        self::assertSame('New prompt', $image->getPrompt());
    }

    public function testUpdateSessionStatusDoesNothingWhenNotAllImagesTerminal(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $service = new PhotoBuilderService($em);
        $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
        $image0  = new PhotoImage($session, 0);
        $image1  = new PhotoImage($session, 1);

        $image0->setStatus(PhotoImageStatus::Completed);
        // image1 remains Pending

        $session->setStatus(PhotoSessionStatus::GeneratingImages);
        $service->updateSessionStatusFromImages($session);

        self::assertSame(PhotoSessionStatus::GeneratingImages, $session->getStatus());
    }

    public function testUpdateSessionStatusSetsImagesReadyWhenAllCompleted(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $service = new PhotoBuilderService($em);
        $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
        $image0  = new PhotoImage($session, 0);
        $image1  = new PhotoImage($session, 1);

        $image0->setStatus(PhotoImageStatus::Completed);
        $image1->setStatus(PhotoImageStatus::Completed);

        $session->setStatus(PhotoSessionStatus::GeneratingImages);
        $service->updateSessionStatusFromImages($session);

        self::assertSame(PhotoSessionStatus::ImagesReady, $session->getStatus());
    }

    public function testUpdateSessionStatusSetsImagesReadyEvenWhenSomeFailed(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $service = new PhotoBuilderService($em);
        $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
        $image0  = new PhotoImage($session, 0);
        $image1  = new PhotoImage($session, 1);

        $image0->setStatus(PhotoImageStatus::Completed);
        $image1->setStatus(PhotoImageStatus::Failed);

        $session->setStatus(PhotoSessionStatus::GeneratingImages);
        $service->updateSessionStatusFromImages($session);

        self::assertSame(PhotoSessionStatus::ImagesReady, $session->getStatus());
    }
}
