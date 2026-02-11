<?php

declare(strict_types=1);

use App\PhotoBuilder\Domain\Entity\PhotoImage;
use App\PhotoBuilder\Domain\Entity\PhotoSession;
use App\PhotoBuilder\Domain\Enum\PhotoImageStatus;
use App\PhotoBuilder\Domain\Enum\PhotoSessionStatus;

describe('PhotoSession', function (): void {
    describe('creation', function (): void {
        it('is created with correct initial state', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'Generate professional images');

            expect($session->getWorkspaceId())->toBe('ws-123')
                ->and($session->getConversationId())->toBe('conv-456')
                ->and($session->getPagePath())->toBe('index.html')
                ->and($session->getUserPrompt())->toBe('Generate professional images')
                ->and($session->getStatus())->toBe(PhotoSessionStatus::GeneratingPrompts)
                ->and($session->getImages())->toBeEmpty()
                ->and($session->getCreatedAt())->toBeInstanceOf(DateTimeImmutable::class);
        });

        it('has null id before persistence', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            expect($session->getId())->toBeNull();
        });
    });

    describe('user prompt', function (): void {
        it('can update user prompt', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'initial');
            $session->setUserPrompt('updated prompt');
            expect($session->getUserPrompt())->toBe('updated prompt');
        });
    });

    describe('status', function (): void {
        it('can transition status', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');

            $session->setStatus(PhotoSessionStatus::PromptsReady);
            expect($session->getStatus())->toBe(PhotoSessionStatus::PromptsReady);

            $session->setStatus(PhotoSessionStatus::GeneratingImages);
            expect($session->getStatus())->toBe(PhotoSessionStatus::GeneratingImages);

            $session->setStatus(PhotoSessionStatus::ImagesReady);
            expect($session->getStatus())->toBe(PhotoSessionStatus::ImagesReady);
        });
    });

    describe('images collection', function (): void {
        it('adds images via addImage', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image   = new PhotoImage($session, 0);

            expect($session->getImages())->toHaveCount(1)
                ->and($session->getImages()->first())->toBe($image);
        });

        it('does not add duplicate images', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image   = new PhotoImage($session, 0);

            // Manually try to add again
            $session->addImage($image);

            expect($session->getImages())->toHaveCount(1);
        });
    });

    describe('areAllImagesTerminal', function (): void {
        it('returns false when no images exist', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            expect($session->areAllImagesTerminal())->toBeFalse();
        });

        it('returns false when some images are pending', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image0  = new PhotoImage($session, 0);
            $image1  = new PhotoImage($session, 1);

            $image0->setStatus(PhotoImageStatus::Completed);
            // image1 remains Pending

            expect($session->areAllImagesTerminal())->toBeFalse();
        });

        it('returns true when all images are completed', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image0  = new PhotoImage($session, 0);
            $image1  = new PhotoImage($session, 1);

            $image0->setStatus(PhotoImageStatus::Completed);
            $image1->setStatus(PhotoImageStatus::Completed);

            expect($session->areAllImagesTerminal())->toBeTrue();
        });

        it('returns true when all images are in terminal state including failed', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image0  = new PhotoImage($session, 0);
            $image1  = new PhotoImage($session, 1);

            $image0->setStatus(PhotoImageStatus::Completed);
            $image1->setStatus(PhotoImageStatus::Failed);

            expect($session->areAllImagesTerminal())->toBeTrue();
        });
    });

    describe('areAllImagesCompleted', function (): void {
        it('returns false when no images exist', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            expect($session->areAllImagesCompleted())->toBeFalse();
        });

        it('returns false when some images failed', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image0  = new PhotoImage($session, 0);
            $image1  = new PhotoImage($session, 1);

            $image0->setStatus(PhotoImageStatus::Completed);
            $image1->setStatus(PhotoImageStatus::Failed);

            expect($session->areAllImagesCompleted())->toBeFalse();
        });

        it('returns true when all images completed', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image0  = new PhotoImage($session, 0);
            $image1  = new PhotoImage($session, 1);

            $image0->setStatus(PhotoImageStatus::Completed);
            $image1->setStatus(PhotoImageStatus::Completed);

            expect($session->areAllImagesCompleted())->toBeTrue();
        });
    });
});
