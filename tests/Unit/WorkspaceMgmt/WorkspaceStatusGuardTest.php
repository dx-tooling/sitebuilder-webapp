<?php

declare(strict_types=1);

namespace App\Tests\Unit\WorkspaceMgmt;

use App\WorkspaceMgmt\Domain\Service\WorkspaceStatusGuard;
use App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus;
use InvalidArgumentException;

describe('WorkspaceStatusGuard', function (): void {
    describe('valid transitions', function (): void {
        it('allows AVAILABLE_FOR_SETUP -> IN_SETUP', function (): void {
            $guard = new WorkspaceStatusGuard();
            $guard->validateTransition(
                WorkspaceStatus::AVAILABLE_FOR_SETUP,
                WorkspaceStatus::IN_SETUP
            );
            expect(true)->toBeTrue();
        });

        it('allows IN_SETUP -> AVAILABLE_FOR_CONVERSATION', function (): void {
            $guard = new WorkspaceStatusGuard();
            $guard->validateTransition(
                WorkspaceStatus::IN_SETUP,
                WorkspaceStatus::AVAILABLE_FOR_CONVERSATION
            );
            expect(true)->toBeTrue();
        });

        it('allows IN_SETUP -> PROBLEM', function (): void {
            $guard = new WorkspaceStatusGuard();
            $guard->validateTransition(
                WorkspaceStatus::IN_SETUP,
                WorkspaceStatus::PROBLEM
            );
            expect(true)->toBeTrue();
        });

        it('allows AVAILABLE_FOR_CONVERSATION -> IN_CONVERSATION', function (): void {
            $guard = new WorkspaceStatusGuard();
            $guard->validateTransition(
                WorkspaceStatus::AVAILABLE_FOR_CONVERSATION,
                WorkspaceStatus::IN_CONVERSATION
            );
            expect(true)->toBeTrue();
        });

        it('allows IN_CONVERSATION -> AVAILABLE_FOR_CONVERSATION', function (): void {
            $guard = new WorkspaceStatusGuard();
            $guard->validateTransition(
                WorkspaceStatus::IN_CONVERSATION,
                WorkspaceStatus::AVAILABLE_FOR_CONVERSATION
            );
            expect(true)->toBeTrue();
        });

        it('allows IN_CONVERSATION -> IN_REVIEW', function (): void {
            $guard = new WorkspaceStatusGuard();
            $guard->validateTransition(
                WorkspaceStatus::IN_CONVERSATION,
                WorkspaceStatus::IN_REVIEW
            );
            expect(true)->toBeTrue();
        });

        it('allows IN_CONVERSATION -> PROBLEM', function (): void {
            $guard = new WorkspaceStatusGuard();
            $guard->validateTransition(
                WorkspaceStatus::IN_CONVERSATION,
                WorkspaceStatus::PROBLEM
            );
            expect(true)->toBeTrue();
        });

        it('allows IN_REVIEW -> MERGED', function (): void {
            $guard = new WorkspaceStatusGuard();
            $guard->validateTransition(
                WorkspaceStatus::IN_REVIEW,
                WorkspaceStatus::MERGED
            );
            expect(true)->toBeTrue();
        });

        it('allows IN_REVIEW -> AVAILABLE_FOR_CONVERSATION', function (): void {
            $guard = new WorkspaceStatusGuard();
            $guard->validateTransition(
                WorkspaceStatus::IN_REVIEW,
                WorkspaceStatus::AVAILABLE_FOR_CONVERSATION
            );
            expect(true)->toBeTrue();
        });

        it('allows MERGED -> IN_SETUP', function (): void {
            $guard = new WorkspaceStatusGuard();
            $guard->validateTransition(
                WorkspaceStatus::MERGED,
                WorkspaceStatus::IN_SETUP
            );
            expect(true)->toBeTrue();
        });

        it('allows PROBLEM -> AVAILABLE_FOR_SETUP', function (): void {
            $guard = new WorkspaceStatusGuard();
            $guard->validateTransition(
                WorkspaceStatus::PROBLEM,
                WorkspaceStatus::AVAILABLE_FOR_SETUP
            );
            expect(true)->toBeTrue();
        });
    });

    describe('invalid transitions', function (): void {
        it('rejects AVAILABLE_FOR_SETUP -> IN_CONVERSATION', function (): void {
            $guard = new WorkspaceStatusGuard();
            expect(fn () => $guard->validateTransition(
                WorkspaceStatus::AVAILABLE_FOR_SETUP,
                WorkspaceStatus::IN_CONVERSATION
            ))->toThrow(InvalidArgumentException::class);
        });

        it('rejects IN_REVIEW -> IN_CONVERSATION', function (): void {
            $guard = new WorkspaceStatusGuard();
            expect(fn () => $guard->validateTransition(
                WorkspaceStatus::IN_REVIEW,
                WorkspaceStatus::IN_CONVERSATION
            ))->toThrow(InvalidArgumentException::class);
        });

        it('rejects PROBLEM -> IN_CONVERSATION', function (): void {
            $guard = new WorkspaceStatusGuard();
            expect(fn () => $guard->validateTransition(
                WorkspaceStatus::PROBLEM,
                WorkspaceStatus::IN_CONVERSATION
            ))->toThrow(InvalidArgumentException::class);
        });

        it('rejects MERGED -> AVAILABLE_FOR_CONVERSATION', function (): void {
            $guard = new WorkspaceStatusGuard();
            expect(fn () => $guard->validateTransition(
                WorkspaceStatus::MERGED,
                WorkspaceStatus::AVAILABLE_FOR_CONVERSATION
            ))->toThrow(InvalidArgumentException::class);
        });

        it('rejects same status transition', function (): void {
            $guard = new WorkspaceStatusGuard();
            expect(fn () => $guard->validateTransition(
                WorkspaceStatus::IN_CONVERSATION,
                WorkspaceStatus::IN_CONVERSATION
            ))->toThrow(InvalidArgumentException::class);
        });
    });

    describe('isValidTransition', function (): void {
        it('returns true for valid transitions', function (): void {
            $guard = new WorkspaceStatusGuard();
            expect($guard->isValidTransition(
                WorkspaceStatus::AVAILABLE_FOR_SETUP,
                WorkspaceStatus::IN_SETUP
            ))->toBeTrue();
        });

        it('returns false for invalid transitions', function (): void {
            $guard = new WorkspaceStatusGuard();
            expect($guard->isValidTransition(
                WorkspaceStatus::AVAILABLE_FOR_SETUP,
                WorkspaceStatus::IN_CONVERSATION
            ))->toBeFalse();
        });
    });

    describe('canStartConversation', function (): void {
        it('returns true for AVAILABLE_FOR_CONVERSATION', function (): void {
            $guard = new WorkspaceStatusGuard();
            expect($guard->canStartConversation(WorkspaceStatus::AVAILABLE_FOR_CONVERSATION))
                ->toBeTrue();
        });

        it('returns true for AVAILABLE_FOR_SETUP', function (): void {
            $guard = new WorkspaceStatusGuard();
            expect($guard->canStartConversation(WorkspaceStatus::AVAILABLE_FOR_SETUP))
                ->toBeTrue();
        });

        it('returns true for MERGED', function (): void {
            $guard = new WorkspaceStatusGuard();
            expect($guard->canStartConversation(WorkspaceStatus::MERGED))
                ->toBeTrue();
        });

        it('returns false for IN_CONVERSATION', function (): void {
            $guard = new WorkspaceStatusGuard();
            expect($guard->canStartConversation(WorkspaceStatus::IN_CONVERSATION))
                ->toBeFalse();
        });

        it('returns false for IN_REVIEW', function (): void {
            $guard = new WorkspaceStatusGuard();
            expect($guard->canStartConversation(WorkspaceStatus::IN_REVIEW))
                ->toBeFalse();
        });

        it('returns false for PROBLEM', function (): void {
            $guard = new WorkspaceStatusGuard();
            expect($guard->canStartConversation(WorkspaceStatus::PROBLEM))
                ->toBeFalse();
        });
    });

    describe('needsSetup', function (): void {
        it('returns true for AVAILABLE_FOR_SETUP', function (): void {
            $guard = new WorkspaceStatusGuard();
            expect($guard->needsSetup(WorkspaceStatus::AVAILABLE_FOR_SETUP))
                ->toBeTrue();
        });

        it('returns true for MERGED', function (): void {
            $guard = new WorkspaceStatusGuard();
            expect($guard->needsSetup(WorkspaceStatus::MERGED))
                ->toBeTrue();
        });

        it('returns false for AVAILABLE_FOR_CONVERSATION', function (): void {
            $guard = new WorkspaceStatusGuard();
            expect($guard->needsSetup(WorkspaceStatus::AVAILABLE_FOR_CONVERSATION))
                ->toBeFalse();
        });

        it('returns false for IN_CONVERSATION', function (): void {
            $guard = new WorkspaceStatusGuard();
            expect($guard->needsSetup(WorkspaceStatus::IN_CONVERSATION))
                ->toBeFalse();
        });
    });
});
