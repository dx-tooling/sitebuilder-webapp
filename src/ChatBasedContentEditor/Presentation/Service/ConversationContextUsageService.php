<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Presentation\Service;

use App\ChatBasedContentEditor\Domain\Entity\Conversation;
use App\ChatBasedContentEditor\Domain\Entity\EditSession;
use App\ChatBasedContentEditor\Domain\Enum\EditSessionStatus;
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
        #[Autowire(param: 'chat_based_content_editor.system_prompt_bytes_estimate')]
        private int                    $systemPromptBytesEstimate,
    ) {
    }

    /**
     * @param string|null $activeSessionId When set and that session is Running, its event chunk bytes are included in usedTokens (current context). When null or session not Running, usedTokens = messages + system prompt only (so the bar can "shrink" when a turn ends). totalCost is always cumulative.
     */
    public function getContextUsage(Conversation $conversation, ?string $activeSessionId = null): ContextUsageDto
    {
        $messagesBytes = (int) $this->entityManager->createQuery(
            'SELECT COALESCE(SUM(LENGTH(m.contentJson)), 0) FROM App\ChatBasedContentEditor\Domain\Entity\ConversationMessage m WHERE m.conversation = :c'
        )
            ->setParameter('c', $conversation)
            ->getSingleScalarResult();

        $allEventChunksBytes = (int) $this->entityManager->createQuery(
            'SELECT COALESCE(SUM(COALESCE(ch.contextBytes, LENGTH(ch.payloadJson))), 0) FROM App\ChatBasedContentEditor\Domain\Entity\EditSessionChunk ch JOIN ch.session s WHERE s.conversation = :c AND ch.chunkType = :event'
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

        $activeSessionEventBytes = 0;
        if ($activeSessionId !== null && $activeSessionId !== '') {
            $session = $this->entityManager->find(EditSession::class, $activeSessionId);
            if ($session instanceof EditSession && $session->getStatus() === EditSessionStatus::Running) {
                $activeSessionEventBytes = (int) $this->entityManager->createQuery(
                    'SELECT COALESCE(SUM(COALESCE(ch.contextBytes, 0)), 0) FROM App\ChatBasedContentEditor\Domain\Entity\EditSessionChunk ch WHERE ch.session = :session AND ch.chunkType = :event'
                )
                    ->setParameter('session', $session)
                    ->setParameter('event', 'event')
                    ->getSingleScalarResult();
            }
        }

        $currentContextBytes = $messagesBytes + $this->systemPromptBytesEstimate + $activeSessionEventBytes;
        $usedTokens          = (int) round($currentContextBytes / $this->bytesPerTokenEstimate);

        $inputBytesCumulative   = $messagesBytes + $allEventChunksBytes;
        $outputBytesCumulative  = $textChunksBytes;
        $inputTokensCumulative  = (int) round($inputBytesCumulative / $this->bytesPerTokenEstimate);
        $outputTokensCumulative = (int) round($outputBytesCumulative / $this->bytesPerTokenEstimate);

        $model     = LlmModelName::defaultForContentEditor();
        $maxTokens = $model->maxContextTokens();

        $inputCostPer1M  = $model->inputCostPer1M();
        $outputCostPer1M = $model->outputCostPer1M();
        $inputCost       = ($inputTokensCumulative / 1_000_000)  * $inputCostPer1M;
        $outputCost      = ($outputTokensCumulative / 1_000_000) * $outputCostPer1M;
        $totalCost       = $inputCost + $outputCost;

        return new ContextUsageDto(
            $usedTokens,
            $maxTokens,
            $model->value,
            $inputTokensCumulative,
            $outputTokensCumulative,
            $inputCost,
            $outputCost,
            $totalCost
        );
    }
}
