<?php

declare(strict_types=1);

use App\PhotoBuilder\Domain\Entity\PhotoImage;
use App\PhotoBuilder\Domain\Entity\PhotoSession;
use App\PhotoBuilder\Domain\Enum\PhotoImageStatus;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;

describe('PhotoImage', function (): void {
    describe('creation', function (): void {
        it('is created with correct initial state', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image   = new PhotoImage($session, 2);

            expect($image->getSession())->toBe($session)
                ->and($image->getPosition())->toBe(2)
                ->and($image->getStatus())->toBe(PhotoImageStatus::Pending)
                ->and($image->getPrompt())->toBeNull()
                ->and($image->getSuggestedFileName())->toBeNull()
                ->and($image->getStoragePath())->toBeNull()
                ->and($image->getErrorMessage())->toBeNull()
                ->and($image->getCreatedAt())->toBeInstanceOf(DateTimeImmutable::class);
        });

        it('automatically adds itself to the session', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image   = new PhotoImage($session, 0);

            expect($session->getImages())->toHaveCount(1)
                ->and($session->getImages()->first())->toBe($image);
        });

        it('has null id before persistence', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image   = new PhotoImage($session, 0);
            expect($image->getId())->toBeNull();
        });

        it('has null uploadedToMediaStoreAt initially', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image   = new PhotoImage($session, 0);
            expect($image->getUploadedToMediaStoreAt())->toBeNull();
        });
    });

    describe('prompt management', function (): void {
        it('can set and get prompt', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image   = new PhotoImage($session, 0);

            $image->setPrompt('A professional office scene');
            expect($image->getPrompt())->toBe('A professional office scene');
        });

        it('can clear prompt by setting null', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image   = new PhotoImage($session, 0);

            $image->setPrompt('Some prompt');
            $image->setPrompt(null);
            expect($image->getPrompt())->toBeNull();
        });
    });

    describe('suggested file name', function (): void {
        it('can set and get suggested file name', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image   = new PhotoImage($session, 0);

            $image->setSuggestedFileName('cozy-cafe-winter-scene.jpg');
            expect($image->getSuggestedFileName())->toBe('cozy-cafe-winter-scene.jpg');
        });
    });

    describe('status transitions', function (): void {
        it('can transition through all states', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image   = new PhotoImage($session, 0);

            expect($image->getStatus())->toBe(PhotoImageStatus::Pending);

            $image->setStatus(PhotoImageStatus::Generating);
            expect($image->getStatus())->toBe(PhotoImageStatus::Generating);

            $image->setStatus(PhotoImageStatus::Completed);
            expect($image->getStatus())->toBe(PhotoImageStatus::Completed);
        });

        it('can transition to failed', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image   = new PhotoImage($session, 0);

            $image->setStatus(PhotoImageStatus::Failed);
            $image->setErrorMessage('API error');

            expect($image->getStatus())->toBe(PhotoImageStatus::Failed)
                ->and($image->getErrorMessage())->toBe('API error');
        });
    });

    describe('isTerminal', function (): void {
        it('returns false for pending', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image   = new PhotoImage($session, 0);
            expect($image->isTerminal())->toBeFalse();
        });

        it('returns false for generating', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image   = new PhotoImage($session, 0);
            $image->setStatus(PhotoImageStatus::Generating);
            expect($image->isTerminal())->toBeFalse();
        });

        it('returns true for completed', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image   = new PhotoImage($session, 0);
            $image->setStatus(PhotoImageStatus::Completed);
            expect($image->isTerminal())->toBeTrue();
        });

        it('returns true for failed', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image   = new PhotoImage($session, 0);
            $image->setStatus(PhotoImageStatus::Failed);
            expect($image->isTerminal())->toBeTrue();
        });
    });

    describe('storage path', function (): void {
        it('can set and get storage path', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image   = new PhotoImage($session, 0);

            $image->setStoragePath('abc-123/0.png');
            expect($image->getStoragePath())->toBe('abc-123/0.png');
        });
    });

    describe('uploadedToMediaStoreAt', function (): void {
        it('can set and get uploadedToMediaStoreAt', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image   = new PhotoImage($session, 0);

            $now = DateAndTimeService::getDateTimeImmutable();
            $image->setUploadedToMediaStoreAt($now);
            expect($image->getUploadedToMediaStoreAt())->toBe($now);
        });

        it('can clear by setting null', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image   = new PhotoImage($session, 0);

            $image->setUploadedToMediaStoreAt(DateAndTimeService::getDateTimeImmutable());
            $image->setUploadedToMediaStoreAt(null);
            expect($image->getUploadedToMediaStoreAt())->toBeNull();
        });
    });

    describe('uploadedFileName', function (): void {
        it('can set and get uploadedFileName', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image   = new PhotoImage($session, 0);

            $image->setUploadedFileName('00fa0883ee6db2e2_placeholder-image-1.png');
            expect($image->getUploadedFileName())->toBe('00fa0883ee6db2e2_placeholder-image-1.png');
        });

        it('can clear by setting null', function (): void {
            $session = new PhotoSession('ws-123', 'conv-456', 'index.html', 'prompt');
            $image   = new PhotoImage($session, 0);

            $image->setUploadedFileName('00fa0883ee6db2e2_image.png');
            $image->setUploadedFileName(null);
            expect($image->getUploadedFileName())->toBeNull();
        });
    });
});
