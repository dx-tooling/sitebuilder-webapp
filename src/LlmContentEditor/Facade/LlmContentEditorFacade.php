<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Facade;

use App\LlmContentEditor\Domain\Agent\ContentEditorAgent;
use App\LlmContentEditor\Domain\Enum\LlmModelName;
use App\LlmContentEditor\Facade\Dto\ConversationMessageDto;
use App\LlmContentEditor\Facade\Dto\EditStreamChunkDto;
use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\LlmContentEditor\Infrastructure\AgentEventQueue;
use App\LlmContentEditor\Infrastructure\ChatHistory\CallbackChatHistory;
use App\LlmContentEditor\Infrastructure\ChatHistory\MessageSerializer;
use App\LlmContentEditor\Infrastructure\Observer\AgentEventCollectingObserver;
use App\WorkspaceTooling\Facade\WorkspaceToolingServiceInterface;
use Generator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use Psr\Log\LoggerInterface;
use SplQueue;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function is_string;

final class LlmContentEditorFacade implements LlmContentEditorFacadeInterface
{
    private readonly MessageSerializer $messageSerializer;

    public function __construct(
        private readonly WorkspaceToolingServiceInterface $workspaceTooling,
        private readonly HttpClientInterface              $httpClient,
        private readonly LoggerInterface                  $logger,
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
        yield new EditStreamChunkDto('done', null, null, false, 'streamEdit is deprecated. Use streamEditWithHistory with an API key.');
    }

    /**
     * @param list<ConversationMessageDto> $previousMessages
     *
     * @return Generator<EditStreamChunkDto>
     */
    public function streamEditWithHistory(
        string $workspacePath,
        string $instruction,
        array  $previousMessages,
        string $llmApiKey
    ): Generator {
        // Convert previous message DTOs to NeuronAI messages
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

        // Create a queue to collect new messages for persistence
        /** @var SplQueue<ConversationMessageDto> $messageQueue */
        $messageQueue = new SplQueue();

        // Create chat history with previous messages and a callback for new ones
        $chatHistory = new CallbackChatHistory($initialMessages);
        $chatHistory->setOnNewMessageCallback(function (Message $message) use ($messageQueue): void {
            // Only persist user and assistant messages for conversation history.
            // Tool call/result messages are internal to a single turn and cause
            // OpenAI API format issues when replayed (400 Bad Request).
            //
            // Additionally, only save messages with actual content to avoid
            // empty or intermediate messages during tool usage flows.
            $shouldSave = match (true) {
                $message instanceof UserMessage      && !$message instanceof ToolCallResultMessage => true,
                $message instanceof AssistantMessage && !$message instanceof ToolCallMessage       => $this->hasNonEmptyContent($message),
                default                                                                            => false,
            };

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
            LlmModelName::defaultForContentEditor(),
            $llmApiKey
        );
        $agent->withChatHistory($chatHistory);

        $queue = new AgentEventQueue();
        $agent->attach(new AgentEventCollectingObserver($queue));

        try {
            $message = new UserMessage($prompt);
            $stream  = $agent->stream($message);

            foreach ($stream as $chunk) {
                // Yield any queued messages for persistence
                while (!$messageQueue->isEmpty()) {
                    yield new EditStreamChunkDto('message', null, null, null, null, $messageQueue->dequeue());
                }

                foreach ($queue->drain() as $eventDto) {
                    yield new EditStreamChunkDto('event', null, $eventDto, null, null);
                }
                if (is_string($chunk)) {
                    yield new EditStreamChunkDto('text', $chunk, null, null, null);
                }
            }

            // Yield any remaining queued messages
            while (!$messageQueue->isEmpty()) {
                yield new EditStreamChunkDto('message', null, null, null, null, $messageQueue->dequeue());
            }

            foreach ($queue->drain() as $eventDto) {
                yield new EditStreamChunkDto('event', null, $eventDto, null, null);
            }

            yield new EditStreamChunkDto('done', null, null, true, null);
        } catch (Throwable $e) {
            yield new EditStreamChunkDto('done', null, null, false, $e->getMessage());
        }
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
     * Check if a message has non-empty content worth persisting.
     */
    private function hasNonEmptyContent(Message $message): bool
    {
        $content = $message->getContent();

        if ($content === null) {
            return false;
        }

        if (is_string($content)) {
            return trim($content) !== '';
        }

        // Arrays or other types - consider them as having content
        return true;
    }
}
