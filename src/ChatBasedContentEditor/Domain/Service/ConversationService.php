<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Domain\Service;

use App\Account\Facade\AccountFacadeInterface;
use App\ChatBasedContentEditor\Domain\Dto\ConversationInfoDto;
use App\ChatBasedContentEditor\Domain\Entity\Conversation;
use App\ChatBasedContentEditor\Domain\Enum\ConversationStatus;
use App\ChatBasedContentEditor\Infrastructure\Service\ConversationUrlServiceInterface;
use App\WorkspaceMgmt\Facade\WorkspaceMgmtFacadeInterface;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * Internal domain service for conversation operations.
 * Used by ChatBasedContentEditorController within the same vertical.
 */
final class ConversationService
{
    public function __construct(
        private readonly EntityManagerInterface          $entityManager,
        private readonly WorkspaceMgmtFacadeInterface    $workspaceMgmtFacade,
        private readonly AccountFacadeInterface          $accountFacade,
        private readonly ConversationUrlServiceInterface $conversationUrlService,
    ) {
    }

    /**
     * Start a new conversation or return existing ongoing one.
     * Assumes workspace is already set up (AVAILABLE_FOR_CONVERSATION status).
     * The controller handles async setup before calling this method.
     */
    public function startOrResumeConversation(string $projectId, string $userId): ConversationInfoDto
    {
        // Get workspace - it must exist and be ready at this point
        $workspaceInfo = $this->workspaceMgmtFacade->getWorkspaceForProject($projectId);

        if ($workspaceInfo === null) {
            throw new RuntimeException('Workspace not found for project: ' . $projectId);
        }

        // Check if user already has an ongoing conversation
        $existingConversation = $this->findOngoingConversation($workspaceInfo->id, $userId);

        if ($existingConversation !== null) {
            return $this->toDto($existingConversation);
        }

        // Transition workspace to IN_CONVERSATION
        $this->workspaceMgmtFacade->transitionToInConversation($workspaceInfo->id);

        // Create new conversation
        $conversation = new Conversation(
            $workspaceInfo->id,
            $userId,
            $workspaceInfo->workspacePath
        );
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();

        return $this->toDto($conversation);
    }

    /**
     * Finish a conversation and make workspace available.
     */
    public function finishConversation(string $conversationId, string $userId): void
    {
        $conversation = $this->getConversationOrFail($conversationId);

        $this->validateConversationOwner($conversation, $userId);

        if ($conversation->getStatus() !== ConversationStatus::ONGOING) {
            throw new RuntimeException('Conversation is not ongoing');
        }

        // Get user email for commit author
        $authorEmail = $this->getAuthorEmail($userId);

        // Generate conversation URL for linking
        $conversationId  = $conversation->getId();
        $conversationUrl = $conversationId !== null ? $this->conversationUrlService->getConversationUrl($conversationId) : null;

        // Commit any pending changes
        $this->workspaceMgmtFacade->commitAndPush(
            $conversation->getWorkspaceId(),
            'Auto-commit on conversation finish',
            $authorEmail,
            $conversationId,
            $conversationUrl
        );

        // Mark conversation as finished
        $conversation->setStatus(ConversationStatus::FINISHED);
        $this->entityManager->flush();

        // Make workspace available for new conversations
        $this->workspaceMgmtFacade->transitionToAvailableForConversation(
            $conversation->getWorkspaceId()
        );
    }

    /**
     * Send conversation to review (finish and create PR).
     * If there are no changes/commits, finishes the conversation and makes workspace available instead.
     *
     * @return string the PR URL, or empty string if no changes (conversation finished, workspace available)
     */
    public function sendToReview(string $conversationId, string $userId): string
    {
        $conversation = $this->getConversationOrFail($conversationId);

        $this->validateConversationOwner($conversation, $userId);

        if ($conversation->getStatus() !== ConversationStatus::ONGOING) {
            throw new RuntimeException('Conversation is not ongoing');
        }

        // Get user email for commit author
        $authorEmail = $this->getAuthorEmail($userId);

        // Generate conversation URL for linking
        $conversationId  = $conversation->getId();
        $conversationUrl = $conversationId !== null ? $this->conversationUrlService->getConversationUrl($conversationId) : null;

        // Commit any pending changes
        $this->workspaceMgmtFacade->commitAndPush(
            $conversation->getWorkspaceId(),
            'Auto-commit before review',
            $authorEmail,
            $conversationId,
            $conversationUrl
        );

        // Mark conversation as finished
        $conversation->setStatus(ConversationStatus::FINISHED);
        $this->entityManager->flush();

        // Try to create PR, but if there are no changes, just finish the conversation
        try {
            // Transition workspace to review
            $this->workspaceMgmtFacade->transitionToInReview($conversation->getWorkspaceId());

            // Ensure PR exists and return URL
            return $this->workspaceMgmtFacade->ensurePullRequest(
                $conversation->getWorkspaceId(),
                $conversationId,
                $conversationUrl,
                $authorEmail
            );
        } catch (RuntimeException $e) {
            // If PR creation fails because there are no changes, finish conversation and make workspace available
            if (str_contains($e->getMessage(), 'branch has no commits or changes')) {
                $this->workspaceMgmtFacade->transitionToAvailableForConversation(
                    $conversation->getWorkspaceId()
                );

                return '';
            }

            // Re-throw other exceptions
            throw $e;
        }
    }

    public function findById(string $id): ?ConversationInfoDto
    {
        $conversation = $this->entityManager->find(Conversation::class, $id);

        if ($conversation === null) {
            return null;
        }

        return $this->toDto($conversation);
    }

    public function findOngoingConversation(string $workspaceId, string $userId): ?Conversation
    {
        /** @var Conversation|null $conversation */
        $conversation = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Conversation::class, 'c')
            ->where('c.workspaceId = :workspaceId')
            ->andWhere('c.userId = :userId')
            ->andWhere('c.status = :status')
            ->setParameter('workspaceId', $workspaceId)
            ->setParameter('userId', $userId)
            ->setParameter('status', ConversationStatus::ONGOING)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $conversation;
    }

    /**
     * Find any ongoing conversation for a workspace (regardless of user).
     */
    public function findAnyOngoingConversationForWorkspace(string $workspaceId): ?Conversation
    {
        /** @var Conversation|null $conversation */
        $conversation = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Conversation::class, 'c')
            ->where('c.workspaceId = :workspaceId')
            ->andWhere('c.status = :status')
            ->setParameter('workspaceId', $workspaceId)
            ->setParameter('status', ConversationStatus::ONGOING)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $conversation;
    }

    private function getConversationOrFail(string $id): Conversation
    {
        $conversation = $this->entityManager->find(Conversation::class, $id);

        if ($conversation === null) {
            throw new RuntimeException('Conversation not found: ' . $id);
        }

        return $conversation;
    }

    private function validateConversationOwner(Conversation $conversation, string $userId): void
    {
        if ($conversation->getUserId() !== $userId) {
            throw new RuntimeException('Only the conversation owner can perform this action');
        }
    }

    private function getAuthorEmail(string $userId): string
    {
        $accountInfo = $this->accountFacade->getAccountInfoById($userId);

        if ($accountInfo === null) {
            throw new RuntimeException('Account not found for user: ' . $userId);
        }

        return $accountInfo->email;
    }

    private function toDto(Conversation $conversation): ConversationInfoDto
    {
        $id = $conversation->getId();
        if ($id === null) {
            throw new RuntimeException('Conversation ID cannot be null');
        }

        return new ConversationInfoDto(
            $id,
            $conversation->getWorkspaceId(),
            $conversation->getUserId(),
            $conversation->getStatus(),
            $conversation->getWorkspacePath(),
            $conversation->getCreatedAt(),
        );
    }
}
