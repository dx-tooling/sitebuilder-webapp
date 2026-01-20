<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Infrastructure\Handler;

use App\ChatBasedContentEditor\Domain\Entity\EditSession;
use App\ChatBasedContentEditor\Domain\Entity\EditSessionChunk;
use App\ChatBasedContentEditor\Domain\Enum\EditSessionStatus;
use App\ChatBasedContentEditor\Infrastructure\Message\RunEditSessionMessage;
use App\LlmContentEditor\Facade\Dto\AgentEventDto;
use App\LlmContentEditor\Facade\Dto\ToolInputEntryDto;
use App\LlmContentEditor\Facade\LlmContentEditorFacadeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

use const JSON_THROW_ON_ERROR;

#[AsMessageHandler]
final readonly class RunEditSessionHandler
{
    public function __construct(
        private EntityManagerInterface          $entityManager,
        private LlmContentEditorFacadeInterface $facade,
        private LoggerInterface                 $logger,
    ) {
    }

    public function __invoke(RunEditSessionMessage $message): void
    {
        $session = $this->entityManager->find(EditSession::class, $message->sessionId);

        if ($session === null) {
            $this->logger->error('EditSession not found', ['sessionId' => $message->sessionId]);

            return;
        }

        $session->setStatus(EditSessionStatus::Running);
        $this->entityManager->flush();

        try {
            $generator = $this->facade->streamEdit(
                $session->getWorkspacePath(),
                $session->getInstruction()
            );

            foreach ($generator as $chunk) {
                if ($chunk->chunkType === 'text' && $chunk->content !== null) {
                    EditSessionChunk::createTextChunk($session, $chunk->content);
                } elseif ($chunk->chunkType === 'event' && $chunk->event !== null) {
                    $eventJson = $this->serializeEvent($chunk->event);
                    EditSessionChunk::createEventChunk($session, $eventJson);
                } elseif ($chunk->chunkType === 'done') {
                    EditSessionChunk::createDoneChunk(
                        $session,
                        $chunk->success ?? false,
                        $chunk->errorMessage
                    );
                }

                $this->entityManager->flush();
            }

            $session->setStatus(EditSessionStatus::Completed);
        } catch (Throwable $e) {
            $this->logger->error('EditSession failed', [
                'sessionId' => $message->sessionId,
                'error'     => $e->getMessage(),
            ]);

            EditSessionChunk::createDoneChunk($session, false, 'An error occurred during processing.');
            $session->setStatus(EditSessionStatus::Failed);
        }

        $this->entityManager->flush();
    }

    private function serializeEvent(AgentEventDto $event): string
    {
        $data = ['kind' => $event->kind];

        if ($event->toolName !== null) {
            $data['toolName'] = $event->toolName;
        }

        if ($event->toolInputs !== null) {
            $data['toolInputs'] = array_map(
                static fn (ToolInputEntryDto $t) => ['key' => $t->key, 'value' => $t->value],
                $event->toolInputs
            );
        }

        if ($event->toolResult !== null) {
            $data['toolResult'] = $event->toolResult;
        }

        if ($event->errorMessage !== null) {
            $data['errorMessage'] = $event->errorMessage;
        }

        return json_encode($data, JSON_THROW_ON_ERROR);
    }
}
