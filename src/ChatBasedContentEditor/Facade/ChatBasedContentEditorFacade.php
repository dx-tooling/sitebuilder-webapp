<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Facade;

use App\ChatBasedContentEditor\Domain\Entity\Conversation;
use App\ChatBasedContentEditor\Domain\Entity\EditSession;
use App\ChatBasedContentEditor\Domain\Entity\EditSessionChunk;
use App\ChatBasedContentEditor\Domain\Enum\ConversationStatus;
use App\ChatBasedContentEditor\Domain\Enum\EditSessionStatus;
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

    public function getLatestConversationId(string $workspaceId): ?string
    {
        /** @var Conversation|null $conversation */
        $conversation = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Conversation::class, 'c')
            ->where('c.workspaceId = :workspaceId')
            ->setParameter('workspaceId', $workspaceId)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $conversation?->getId();
    }

    public function recoverStuckEditSessions(int $runningTimeoutMinutes = 30, int $cancellingTimeoutMinutes = 2): int
    {
        $recovered = 0;

        // Recover sessions stuck in Running
        $runningCutoff = DateAndTimeService::getDateTimeImmutable()->modify("-{$runningTimeoutMinutes} minutes");

        /** @var list<EditSession> $stuckRunning */
        $stuckRunning = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(EditSession::class, 's')
            ->where('s.status = :status')
            ->andWhere('s.createdAt < :cutoff')
            ->setParameter('status', EditSessionStatus::Running)
            ->setParameter('cutoff', $runningCutoff)
            ->getQuery()
            ->getResult();

        foreach ($stuckRunning as $session) {
            EditSessionChunk::createDoneChunk($session, false, 'Session timed out.');
            $session->setStatus(EditSessionStatus::Failed);
            ++$recovered;
        }

        // Recover sessions stuck in Cancelling
        $cancellingCutoff = DateAndTimeService::getDateTimeImmutable()->modify("-{$cancellingTimeoutMinutes} minutes");

        /** @var list<EditSession> $stuckCancelling */
        $stuckCancelling = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(EditSession::class, 's')
            ->where('s.status = :status')
            ->andWhere('s.createdAt < :cutoff')
            ->setParameter('status', EditSessionStatus::Cancelling)
            ->setParameter('cutoff', $cancellingCutoff)
            ->getQuery()
            ->getResult();

        foreach ($stuckCancelling as $session) {
            EditSessionChunk::createDoneChunk($session, false, 'Cancelled by user.');
            $session->setStatus(EditSessionStatus::Cancelled);
            ++$recovered;
        }

        if ($recovered > 0) {
            $this->entityManager->flush();
        }

        return $recovered;
    }
}
