<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Facade;

use App\ChatBasedContentEditor\Domain\Entity\Conversation;
use App\ChatBasedContentEditor\Domain\Enum\ConversationStatus;
use App\WorkspaceMgmt\Facade\WorkspaceMgmtFacadeInterface;
use Doctrine\ORM\EntityManagerInterface;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;

/**
 * Facade implementation for ChatBasedContentEditor operations.
 */
final class ChatBasedContentEditorFacade implements ChatBasedContentEditorFacadeInterface
{
    public function __construct(
        private readonly EntityManagerInterface       $entityManager,
        private readonly WorkspaceMgmtFacadeInterface $workspaceMgmtFacade,
    ) {
    }

    public function finishAllOngoingConversationsForWorkspace(string $workspaceId): int
    {
        /** @var list<Conversation> $conversations */
        $conversations = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Conversation::class, 'c')
            ->where('c.workspaceId = :workspaceId')
            ->andWhere('c.status = :status')
            ->setParameter('workspaceId', $workspaceId)
            ->setParameter('status', ConversationStatus::ONGOING)
            ->getQuery()
            ->getResult();

        foreach ($conversations as $conversation) {
            $conversation->setStatus(ConversationStatus::FINISHED);
        }

        $this->entityManager->flush();

        return count($conversations);
    }

    public function getOngoingConversationUserId(string $workspaceId): ?string
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

        return $conversation?->getUserId();
    }

    public function releaseStaleConversations(int $timeoutMinutes = 5): array
    {
        $cutoffTime = DateAndTimeService::getDateTimeImmutable()->modify("-{$timeoutMinutes} minutes");

        // Find ongoing conversations where:
        // - lastActivityAt is set and is older than cutoff time, OR
        // - lastActivityAt is null and createdAt is older than cutoff time (legacy/new conversations)
        /** @var list<Conversation> $staleConversations */
        $staleConversations = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Conversation::class, 'c')
            ->where('c.status = :status')
            ->andWhere(
                '(c.lastActivityAt IS NOT NULL AND c.lastActivityAt < :cutoffTime) OR ' .
                '(c.lastActivityAt IS NULL AND c.createdAt < :cutoffTime)'
            )
            ->setParameter('status', ConversationStatus::ONGOING)
            ->setParameter('cutoffTime', $cutoffTime)
            ->getQuery()
            ->getResult();

        $releasedWorkspaceIds = [];

        foreach ($staleConversations as $conversation) {
            // Mark conversation as finished
            $conversation->setStatus(ConversationStatus::FINISHED);

            // Collect workspace ID for transition
            $workspaceId = $conversation->getWorkspaceId();
            if (!in_array($workspaceId, $releasedWorkspaceIds, true)) {
                $releasedWorkspaceIds[] = $workspaceId;
            }
        }

        $this->entityManager->flush();

        // Transition workspaces to available
        foreach ($releasedWorkspaceIds as $workspaceId) {
            $this->workspaceMgmtFacade->transitionToAvailableForConversation($workspaceId);
        }

        return $releasedWorkspaceIds;
    }
}
