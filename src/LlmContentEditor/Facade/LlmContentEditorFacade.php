<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Facade;

use App\LlmContentEditor\Domain\Agent\ContentEditorAgent;
use App\LlmContentEditor\Facade\Dto\ConversationMessageDto;
use App\LlmContentEditor\Facade\Dto\EditStreamChunkDto;
use App\LlmContentEditor\Infrastructure\AgentEventQueue;
use App\LlmContentEditor\Infrastructure\ChatHistory\CallbackChatHistory;
use App\LlmContentEditor\Infrastructure\ChatHistory\MessageSerializer;
use App\LlmContentEditor\Infrastructure\Observer\AgentEventCollectingObserver;
use App\WorkspaceTooling\Facade\WorkspaceToolingServiceInterface;
use Generator;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use SplQueue;
use Throwable;

final class LlmContentEditorFacade implements LlmContentEditorFacadeInterface
{
    private readonly MessageSerializer $messageSerializer;

    public function __construct(
        private readonly WorkspaceToolingServiceInterface $workspaceTooling
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
        // Delegate to streamEditWithHistory with empty history
        yield from $this->streamEditWithHistory($workspacePath, $instruction, []);
    }

    /**
     * @param list<ConversationMessageDto> $previousMessages
     *
     * @return Generator<EditStreamChunkDto>
     */
    public function streamEditWithHistory(
        string $workspacePath,
        string $instruction,
        array  $previousMessages = []
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
        if ($isFirstMessage) {
            $prompt = sprintf(
                'The working folder is: %s' . "\n\n" . 'Please perform the following task: %s',
                $workspacePath,
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
            try {
                $dto = $this->messageSerializer->toDto($message);
                $messageQueue->enqueue($dto);
            } catch (Throwable) {
                // Skip messages that can't be serialized
            }
        });

        $agent = new ContentEditorAgent($this->workspaceTooling);
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
}
