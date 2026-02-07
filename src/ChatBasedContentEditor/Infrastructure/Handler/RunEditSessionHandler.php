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
use App\LlmContentEditor\Facade\Dto\AgentConfigDto;
use App\LlmContentEditor\Facade\Dto\AgentEventDto;
use App\LlmContentEditor\Facade\Dto\ConversationMessageDto;
use App\LlmContentEditor\Facade\Dto\ToolInputEntryDto;
use App\LlmContentEditor\Facade\Enum\EditStreamChunkType;
use App\LlmContentEditor\Facade\LlmContentEditorFacadeInterface;
use App\ProjectMgmt\Facade\ProjectMgmtFacadeInterface;
use App\WorkspaceMgmt\Facade\WorkspaceMgmtFacadeInterface;
use App\WorkspaceTooling\Facade\AgentExecutionContextInterface;
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
        private ProjectMgmtFacadeInterface      $projectMgmtFacade,
        private AccountFacadeInterface          $accountFacade,
        private ConversationUrlServiceInterface $conversationUrlService,
        private AgentExecutionContextInterface  $executionContext,
    ) {
    }

    public function __invoke(RunEditSessionMessage $message): void
    {
        $session = $this->entityManager->find(EditSession::class, $message->sessionId);

        if ($session === null) {
            $this->logger->error('EditSession not found', ['sessionId' => $message->sessionId]);

            return;
        }

        // Pre-start cancellation check: if cancel arrived before the worker picked this up
        if ($session->getStatus() === EditSessionStatus::Cancelling) {
            EditSessionChunk::createDoneChunk($session, false, 'Cancelled before execution started.');
            $session->setStatus(EditSessionStatus::Cancelled);
            $this->entityManager->flush();

            return;
        }

        $session->setStatus(EditSessionStatus::Running);
        $this->entityManager->flush();

        try {
            // Load previous messages from conversation
            $previousMessages = $this->loadPreviousMessages($session);
            $conversation     = $session->getConversation();

            // Set execution context for agent container execution
            $workspace = $this->workspaceMgmtFacade->getWorkspaceById($conversation->getWorkspaceId());
            $project   = $workspace !== null ? $this->projectMgmtFacade->getProjectInfo($workspace->projectId) : null;

            // Ensure we have a valid LLM API key from the project
            if ($project === null || $project->llmApiKey === '') {
                $this->logger->error('EditSession failed: no LLM API key configured for project', [
                    'sessionId'   => $message->sessionId,
                    'workspaceId' => $conversation->getWorkspaceId(),
                ]);

                EditSessionChunk::createDoneChunk($session, false, 'No LLM API key configured for this project.');
                $session->setStatus(EditSessionStatus::Failed);
                $this->entityManager->flush();

                return;
            }

            $this->executionContext->setContext(
                $conversation->getWorkspaceId(),
                $session->getWorkspacePath(),
                $conversation->getId(),
                $workspace->projectName,
                $project->agentImage,
                $project->remoteContentAssetsManifestUrls
            );

            // Build agent configuration from project settings.
            // Pass working folder path so it is in the system prompt and survives context-window trimming (#79).
            $agentConfig = new AgentConfigDto(
                $project->agentBackgroundInstructions,
                $project->agentStepInstructions,
                $project->agentOutputInstructions,
                '/workspace',
            );

            $generator = $this->facade->streamEditWithHistory(
                $session->getWorkspacePath(),
                $session->getInstruction(),
                $previousMessages,
                $project->llmApiKey,
                $agentConfig,
                $message->locale,
            );

            foreach ($generator as $chunk) {
                // Cooperative cancellation: check status directly via DBAL to avoid
                // entityManager->refresh() which fails on readonly entity properties.
                $currentStatus = $this->entityManager->getConnection()->fetchOne(
                    'SELECT status FROM edit_sessions WHERE id = ?',
                    [$session->getId()]
                );

                if ($currentStatus === EditSessionStatus::Cancelling->value) {
                    // Persist a synthetic assistant message so the LLM on the next turn
                    // understands this turn was interrupted and won't try to answer it.
                    new ConversationMessage(
                        $conversation,
                        ConversationMessageRole::Assistant,
                        json_encode(
                            ['content' => '[Cancelled by the user — disregard this turn.]'],
                            JSON_THROW_ON_ERROR
                        )
                    );

                    EditSessionChunk::createDoneChunk($session, false, 'Cancelled by user.');
                    $session->setStatus(EditSessionStatus::Cancelled);
                    $this->entityManager->flush();

                    return;
                }

                if ($chunk->chunkType === EditStreamChunkType::Text && $chunk->content !== null) {
                    EditSessionChunk::createTextChunk($session, $chunk->content);
                } elseif ($chunk->chunkType === EditStreamChunkType::Event && $chunk->event !== null) {
                    $eventJson    = $this->serializeEvent($chunk->event);
                    $contextBytes = ($chunk->event->inputBytes ?? 0) + ($chunk->event->resultBytes ?? 0);
                    EditSessionChunk::createEventChunk($session, $eventJson, $contextBytes > 0 ? $contextBytes : null);
                } elseif ($chunk->chunkType === EditStreamChunkType::Progress && $chunk->content !== null) {
                    EditSessionChunk::createProgressChunk($session, $chunk->content);
                } elseif ($chunk->chunkType === EditStreamChunkType::Message && $chunk->message !== null) {
                    // Persist new conversation messages
                    $this->persistConversationMessage($conversation, $chunk->message);
                } elseif ($chunk->chunkType === EditStreamChunkType::Done) {
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
        } finally {
            // Always clear execution context
            $this->executionContext->clearContext();
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

            // Use agent-suggested commit message if available, otherwise fall back to instruction-based message
            $suggestedMessage = $this->executionContext->getSuggestedCommitMessage();
            $commitMessage    = $suggestedMessage ?? 'Edit session: ' . $this->truncateMessage($session->getInstruction(), 50);

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
