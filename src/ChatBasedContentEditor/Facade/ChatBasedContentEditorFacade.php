<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Facade;

use App\ChatBasedContentEditor\Domain\Entity\Conversation;
use App\ChatBasedContentEditor\Domain\Enum\ConversationStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Facade implementation for ChatBasedContentEditor operations.
 */
final class ChatBasedContentEditorFacade implements ChatBasedContentEditorFacadeInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
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
}
