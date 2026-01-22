<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Infrastructure\Handler;

use App\Account\Facade\AccountFacadeInterface;
use App\ChatBasedContentEditor\Domain\Entity\Conversation;
use App\ChatBasedContentEditor\Domain\Entity\ConversationMessage;
use App\ChatBasedContentEditor\Domain\Entity\EditSession;
use App\ChatBasedContentEditor\Domain\Entity\EditSessionChunk;
use App\ChatBasedContentEditor\Domain\Enum\ConversationMessageRole;
use App\ChatBasedContentEditor\Domain\Enum\EditSessionStatus;
use App\ChatBasedContentEditor\Infrastructure\Message\RunEditSessionMessage;
use App\ChatBasedContentEditor\Infrastructure\Service\ConversationUrlServiceInterface;
use App\LlmContentEditor\Facade\Dto\AgentEventDto;
use App\LlmContentEditor\Facade\Dto\ConversationMessageDto;
use App\LlmContentEditor\Facade\Dto\ToolInputEntryDto;
use App\LlmContentEditor\Facade\LlmContentEditorFacadeInterface;
use App\WorkspaceMgmt\Facade\WorkspaceMgmtFacadeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

use function mb_strlen;
use function mb_substr;

use const JSON_THROW_ON_ERROR;

#[AsMessageHandler]
final readonly class RunEditSessionHandler
{
    public function __construct(
        private EntityManagerInterface          $entityManager,
        private LlmContentEditorFacadeInterface $facade,
        private LoggerInterface                 $logger,
        private WorkspaceMgmtFacadeInterface    $workspaceMgmtFacade,
        private AccountFacadeInterface          $accountFacade,
        private ConversationUrlServiceInterface $conversationUrlService,
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
            // Load previous messages from conversation
            $previousMessages = $this->loadPreviousMessages($session);
            $conversation     = $session->getConversation();

            $generator = $this->facade->streamEditWithHistory(
                $session->getWorkspacePath(),
                $session->getInstruction(),
                $previousMessages
            );

            foreach ($generator as $chunk) {
                if ($chunk->chunkType === 'text' && $chunk->content !== null) {
                    EditSessionChunk::createTextChunk($session, $chunk->content);
                } elseif ($chunk->chunkType === 'event' && $chunk->event !== null) {
                    $eventJson = $this->serializeEvent($chunk->event);
                    EditSessionChunk::createEventChunk($session, $eventJson);
                } elseif ($chunk->chunkType === 'message' && $chunk->message !== null) {
                    // Persist new conversation messages
                    $this->persistConversationMessage($conversation, $chunk->message);
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
            $this->entityManager->flush();

            // Commit and push changes after successful edit session
            $this->commitChangesAfterEdit($conversation, $session);
        } catch (Throwable $e) {
            $this->logger->error('EditSession failed', [
                'sessionId' => $message->sessionId,
                'error'     => $e->getMessage(),
            ]);

            EditSessionChunk::createDoneChunk($session, false, 'An error occurred during processing.');
            $session->setStatus(EditSessionStatus::Failed);
            $this->entityManager->flush();
        }
    }

    /**
     * Load previous messages from the conversation.
     *
     * @return list<ConversationMessageDto>
     */
    private function loadPreviousMessages(EditSession $session): array
    {
        $conversation = $session->getConversation();

        $messages = [];
        foreach ($conversation->getMessages() as $message) {
            $messages[] = new ConversationMessageDto(
                $message->getRole()->value,
                $message->getContentJson()
            );
        }

        return $messages;
    }

    /**
     * Persist a new message to the conversation.
     */
    private function persistConversationMessage(Conversation $conversation, ConversationMessageDto $dto): void
    {
        // The DTO role is constrained to valid values, so from() should always succeed
        $role = ConversationMessageRole::from($dto->role);

        new ConversationMessage($conversation, $role, $dto->contentJson);
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

    /**
     * Commit and push changes after a successful edit session.
     */
    private function commitChangesAfterEdit(Conversation $conversation, EditSession $session): void
    {
        try {
            $accountInfo = $this->accountFacade->getAccountInfoById($conversation->getUserId());

            if ($accountInfo === null) {
                $this->logger->warning('Cannot commit: account not found for user', [
                    'userId' => $conversation->getUserId(),
                ]);

                return;
            }

            $commitMessage = 'Edit session: ' . $this->truncateMessage($session->getInstruction(), 50);

            // Generate conversation URL for linking
            $conversationId  = $conversation->getId();
            $conversationUrl = $conversationId !== null ? $this->conversationUrlService->getConversationUrl($conversationId) : null;

            $this->workspaceMgmtFacade->commitAndPush(
                $conversation->getWorkspaceId(),
                $commitMessage,
                $accountInfo->email,
                $conversationId,
                $conversationUrl
            );

            $this->logger->debug('Committed and pushed changes after edit session', [
                'sessionId'   => $session->getId(),
                'workspaceId' => $conversation->getWorkspaceId(),
            ]);
        } catch (Throwable $e) {
            // Log but don't fail the edit session - the commit can be retried later
            // The workspace will be set to PROBLEM status by the facade
            $this->logger->error('Failed to commit and push after edit session', [
                'sessionId'   => $session->getId(),
                'workspaceId' => $conversation->getWorkspaceId(),
                'error'       => $e->getMessage(),
            ]);
        }
    }

    private function truncateMessage(string $message, int $maxLength): string
    {
        if (mb_strlen($message) <= $maxLength) {
            return $message;
        }

        return mb_substr($message, 0, $maxLength - 3) . '...';
    }
}
