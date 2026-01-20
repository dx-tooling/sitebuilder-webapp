<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Presentation\Service;

use App\ChatBasedContentEditor\Domain\Entity\Conversation;
use App\ChatBasedContentEditor\Presentation\Dto\ContextUsageDto;
use App\LlmContentEditor\Domain\Enum\LlmModelName;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ConversationContextUsageService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire(param: 'chat_based_content_editor.bytes_per_token_estimate')]
        private int                    $bytesPerTokenEstimate,
    ) {
    }

    public function getContextUsage(Conversation $conversation): ContextUsageDto
    {
        $messagesBytes = (int) $this->entityManager->createQuery(
            'SELECT COALESCE(SUM(LENGTH(m.contentJson)), 0) FROM App\ChatBasedContentEditor\Domain\Entity\ConversationMessage m WHERE m.conversation = :c'
        )
            ->setParameter('c', $conversation)
            ->getSingleScalarResult();

        $eventChunksBytes = (int) $this->entityManager->createQuery(
            'SELECT COALESCE(SUM(LENGTH(ch.payloadJson)), 0) FROM App\ChatBasedContentEditor\Domain\Entity\EditSessionChunk ch JOIN ch.session s WHERE s.conversation = :c AND ch.chunkType = :event'
        )
            ->setParameter('c', $conversation)
            ->setParameter('event', 'event')
            ->getSingleScalarResult();

        $usedBytes  = $messagesBytes + $eventChunksBytes;
        $usedTokens = (int) round($usedBytes / $this->bytesPerTokenEstimate);
        $model      = LlmModelName::defaultForContentEditor();
        $maxTokens  = $model->maxContextTokens();

        return new ContextUsageDto($usedTokens, $maxTokens, $model->value);
    }
}
