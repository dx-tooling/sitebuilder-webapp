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

        $textChunksBytes = (int) $this->entityManager->createQuery(
            'SELECT COALESCE(SUM(LENGTH(ch.payloadJson)), 0) FROM App\ChatBasedContentEditor\Domain\Entity\EditSessionChunk ch JOIN ch.session s WHERE s.conversation = :c AND ch.chunkType = :text'
        )
            ->setParameter('c', $conversation)
            ->setParameter('text', 'text')
            ->getSingleScalarResult();

        $inputBytes   = $messagesBytes + $eventChunksBytes;
        $inputTokens  = (int) round($inputBytes / $this->bytesPerTokenEstimate);
        $outputBytes  = $textChunksBytes;
        $outputTokens = (int) round($outputBytes / $this->bytesPerTokenEstimate);
        $usedTokens   = $inputTokens + $outputTokens;

        $model     = LlmModelName::defaultForContentEditor();
        $maxTokens = $model->maxContextTokens();

        $inputCostPer1M  = $model->inputCostPer1M();
        $outputCostPer1M = $model->outputCostPer1M();
        $inputCost       = ($inputTokens / 1_000_000)  * $inputCostPer1M;
        $outputCost      = ($outputTokens / 1_000_000) * $outputCostPer1M;
        $totalCost       = $inputCost + $outputCost;

        return new ContextUsageDto(
            $usedTokens,
            $maxTokens,
            $model->value,
            $inputTokens,
            $outputTokens,
            $inputCost,
            $outputCost,
            $totalCost
        );
    }
}
