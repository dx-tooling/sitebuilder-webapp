<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Facade;

use App\LlmContentEditor\Domain\Agent\ContentEditorAgent;
use App\LlmContentEditor\Domain\Enum\LlmModelName;
use App\LlmContentEditor\Facade\Dto\AgentConfigDto;
use App\LlmContentEditor\Facade\Dto\ConversationMessageDto;
use App\LlmContentEditor\Facade\Dto\EditStreamChunkDto;
use App\LlmContentEditor\Facade\Enum\EditStreamChunkType;
use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\LlmContentEditor\Infrastructure\AgentEventQueue;
use App\LlmContentEditor\Infrastructure\ChatHistory\CallbackChatHistory;
use App\LlmContentEditor\Infrastructure\ChatHistory\MessageSerializer;
use App\LlmContentEditor\Infrastructure\ConversationLog\LlmConversationLogObserver;
use App\LlmContentEditor\Infrastructure\Observer\AgentEventCollectingObserver;
use App\LlmContentEditor\Infrastructure\ProgressMessageResolver;
use App\WorkspaceTooling\Facade\WorkspaceToolingServiceInterface;
use Generator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\SystemPrompt;
use Psr\Log\LoggerInterface;
use SplQueue;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function is_array;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function sprintf;
use function trim;

use const JSON_THROW_ON_ERROR;

final class LlmContentEditorFacade implements LlmContentEditorFacadeInterface
{
    private readonly MessageSerializer $messageSerializer;

    public function __construct(
        private readonly WorkspaceToolingServiceInterface $workspaceTooling,
        private readonly HttpClientInterface              $httpClient,
        private readonly LoggerInterface                  $logger,
        private readonly LoggerInterface                  $llmWireLogger,
        private readonly LoggerInterface                  $llmConversationLogger,
        private readonly bool                             $llmWireLogEnabled,
        private readonly ProgressMessageResolver          $progressMessageResolver,
    ) {
        $this->messageSerializer = new MessageSerializer();
    }

    /**
     * @deprecated Use streamEditWithHistory() to support multi-turn conversations
     *
     * @return Generator<EditStreamChunkDto>
     */
    public function streamEdit(string $workspacePath, string $instruction): Generator
    {
        // This method is deprecated and should not be used.
        // Yield an error chunk since we can't proceed without an API key.
        yield new EditStreamChunkDto(EditStreamChunkType::Done, null, null, false, 'streamEdit is deprecated. Use streamEditWithHistory with an API key.');
    }

    /**
     * @param list<ConversationMessageDto> $previousMessages
     *
     * @return Generator<EditStreamChunkDto>
     */
    public function streamEditWithHistory(
        string         $workspacePath,
        string         $instruction,
        array          $previousMessages,
        string         $llmApiKey,
        AgentConfigDto $agentConfig,
        string         $locale = 'en',
    ): Generator {
        // Convert previous message DTOs to NeuronAI messages. Include turn_activity_summary so the
        // context is conversation-shaped: user, assistant, turn_activity_summary, user, assistant, ...
        $initialMessages = [];
        foreach ($previousMessages as $dto) {
            try {
                $initialMessages[] = $this->messageSerializer->fromDto($dto);
            } catch (Throwable) {
                // Skip invalid messages
            }
        }

        // Determine if this is the first message (needs workspace context)
        $isFirstMessage = $initialMessages === [];

        // Build the user prompt
        // The agent always sees /workspace as its working directory - the actual path
        // is handled by IsolatedShellExecutor which mounts the real workspace there
        if ($isFirstMessage) {
            $prompt = sprintf(
                'The working folder is: %s' . "\n\n" . 'Please perform the following task: %s',
                '/workspace',
                $instruction
            );
        } else {
            // Follow-up messages don't need the workspace context repeated
            $prompt = $instruction;
        }

        // Create a queue to collect new messages for persistence.
        /** @var SplQueue<ConversationMessageDto> $messageQueue */
        $messageQueue = new SplQueue();

        // Create chat history with previous messages and a callback for new ones.
        // Use the model's actual context window so trimming matches the model's capacity.
        $model       = LlmModelName::defaultForContentEditor();
        $chatHistory = new CallbackChatHistory($initialMessages, $model->maxContextTokens());
        $chatHistory->setOnNewMessageCallback(function (Message $message) use ($messageQueue): void {
            if ($message instanceof AssistantMessage) {
                $content = $message->getContent();
                if (is_string($content) && trim($content) !== '') {
                    try {
                        $messageQueue->enqueue($this->messageSerializer->toDto($message));
                    } catch (Throwable) {
                        // Skip messages that can't be serialized
                    }
                }

                return;
            }

            // Persist user messages. Tool call/result messages are never persisted.
            $shouldSave = $message instanceof UserMessage && !$message instanceof ToolCallResultMessage;
            if (!$shouldSave) {
                return;
            }

            try {
                $dto = $this->messageSerializer->toDto($message);
                $messageQueue->enqueue($dto);
            } catch (Throwable) {
                // Skip messages that can't be serialized
            }
        });

        $agent = new ContentEditorAgent(
            $this->workspaceTooling,
            $model,
            $llmApiKey,
            $agentConfig,
            $this->llmWireLogEnabled ? $this->llmWireLogger : null,
        );
        $agent->withChatHistory($chatHistory);

        $queue = new AgentEventQueue();
        $agent->attach(new AgentEventCollectingObserver($queue));

        if ($this->llmWireLogEnabled) {
            $agent->attach(new LlmConversationLogObserver($this->llmConversationLogger));
            $this->llmConversationLogger->info(sprintf('USER → %s', $prompt));
        }

        try {
            $message = new UserMessage($prompt);
            $stream  = $agent->stream($message);

            $accumulatedContent = '';

            foreach ($stream as $chunk) {
                // Yield any queued messages for persistence
                while (!$messageQueue->isEmpty()) {
                    yield new EditStreamChunkDto(EditStreamChunkType::Message, null, null, null, null, $messageQueue->dequeue());
                }

                foreach ($queue->drain() as $eventDto) {
                    yield new EditStreamChunkDto(EditStreamChunkType::Event, null, $eventDto, null, null);
                    $progressMessage = $this->progressMessageResolver->messageForEvent($eventDto, $locale);
                    if ($progressMessage !== null) {
                        yield new EditStreamChunkDto(EditStreamChunkType::Progress, $progressMessage, null, null, null);
                    }
                }
                if (is_string($chunk)) {
                    $accumulatedContent .= $chunk;
                    yield new EditStreamChunkDto(EditStreamChunkType::Text, $chunk, null, null, null);
                }
            }

            if ($this->llmWireLogEnabled) {
                $this->llmConversationLogger->info(sprintf('ASSISTANT → %s', $this->truncateForLog($accumulatedContent, 300)));
            }

            // Yield any remaining queued messages (assistant message from callback)
            while (!$messageQueue->isEmpty()) {
                yield new EditStreamChunkDto(EditStreamChunkType::Message, null, null, null, null, $messageQueue->dequeue());
            }

            // Persist the turn activity journal as a turn_activity_summary for cross-turn memory
            $journalSummary = $chatHistory->getTurnActivitySummary();
            if ($journalSummary !== '') {
                $noteDto = new ConversationMessageDto(
                    ConversationMessageDto::ROLE_TURN_ACTIVITY_SUMMARY,
                    json_encode(['content' => $journalSummary], JSON_THROW_ON_ERROR),
                );
                yield new EditStreamChunkDto(EditStreamChunkType::Message, null, null, null, null, $noteDto);
            }

            foreach ($queue->drain() as $eventDto) {
                yield new EditStreamChunkDto(EditStreamChunkType::Event, null, $eventDto, null, null);
                $progressMessage = $this->progressMessageResolver->messageForEvent($eventDto, $locale);
                if ($progressMessage !== null) {
                    yield new EditStreamChunkDto(EditStreamChunkType::Progress, $progressMessage, null, null, null);
                }
            }

            yield new EditStreamChunkDto(EditStreamChunkType::Done, null, null, true, null);
        } catch (Throwable $e) {
            if ($this->llmWireLogEnabled) {
                $this->llmConversationLogger->info(sprintf('ERROR → %s', $e->getMessage()));
            }
            // Even on error, persist what journal we have for cross-turn context
            $journalSummary = $chatHistory->getTurnActivitySummary();
            if ($journalSummary !== '') {
                try {
                    $noteDto = new ConversationMessageDto(
                        ConversationMessageDto::ROLE_TURN_ACTIVITY_SUMMARY,
                        json_encode(['content' => $journalSummary], JSON_THROW_ON_ERROR),
                    );
                    yield new EditStreamChunkDto(EditStreamChunkType::Message, null, null, null, null, $noteDto);
                } catch (Throwable) {
                    // Ignore serialization errors in error path
                }
            }
            yield new EditStreamChunkDto(EditStreamChunkType::Done, null, null, false, $e->getMessage());
        }
    }

    /**
     * @param list<ConversationMessageDto> $previousMessages
     */
    public function buildAgentContextDump(
        string         $instruction,
        array          $previousMessages,
        AgentConfigDto $agentConfig
    ): string {
        // Build the system prompt using the same logic as ContentEditorAgent::instructions()
        $systemPrompt = (string) new SystemPrompt(
            explode("\n", $agentConfig->backgroundInstructions),
            explode("\n", $agentConfig->stepInstructions),
            explode("\n", $agentConfig->outputInstructions),
        );
        if ($agentConfig->workingFolderPath !== null && $agentConfig->workingFolderPath !== '') {
            $systemPrompt .= "\n\nWORKING FOLDER (use for all path-based tools): " . $agentConfig->workingFolderPath;
        }

        $isFirstMessage = $previousMessages === [];

        $lines   = [];
        $lines[] = '=== SYSTEM PROMPT ===';
        $lines[] = '';
        $lines[] = $systemPrompt;
        $lines[] = '';

        // Format conversation history (includes turn activity summaries so dump reflects full agent context)
        if ($previousMessages !== []) {
            $lines[] = '=== CONVERSATION HISTORY ===';
            $lines[] = '';

            foreach ($previousMessages as $msg) {
                $label   = $msg->role === ConversationMessageDto::ROLE_TURN_ACTIVITY_SUMMARY ? 'TURN ACTIVITY SUMMARY' : strtoupper($msg->role);
                $lines[] = '--- ' . $label . ' ---';

                $decoded = json_decode($msg->contentJson, true);
                if (is_array($decoded) && array_key_exists('content', $decoded) && is_string($decoded['content'])) {
                    $lines[] = $decoded['content'];
                } else {
                    // For tool calls or complex content, show the raw JSON
                    $lines[] = $msg->contentJson;
                }

                $lines[] = '';
            }
        }

        // Format the current user instruction (as it would be sent)
        $lines[] = '=== CURRENT USER MESSAGE ===';
        $lines[] = '';

        if ($isFirstMessage) {
            $lines[] = sprintf(
                'The working folder is: %s' . "\n\n" . 'Please perform the following task: %s',
                '/workspace',
                $instruction
            );
        } else {
            $lines[] = $instruction;
        }

        return implode("\n", $lines);
    }

    public function verifyApiKey(LlmModelProvider $provider, string $apiKey): bool
    {
        if ($apiKey === '') {
            return false;
        }

        try {
            return match ($provider) {
                LlmModelProvider::OpenAI => $this->verifyOpenAiKey($apiKey),
            };
        } catch (Throwable $e) {
            $this->logger->warning('API key verification failed', [
                'provider' => $provider->value,
                'error'    => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Verifies an OpenAI API key by calling the models endpoint.
     */
    private function verifyOpenAiKey(string $apiKey): bool
    {
        $response = $this->httpClient->request('GET', 'https://api.openai.com/v1/models', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
            ],
            'timeout' => 10,
        ]);

        return $response->getStatusCode() === 200;
    }

    /**
     * Truncate a string for human-readable conversation log output.
     */
    private function truncateForLog(string $value, int $maxLength): string
    {
        if (mb_strlen($value) > $maxLength) {
            return mb_substr($value, 0, $maxLength) . '…';
        }

        return $value;
    }
}
