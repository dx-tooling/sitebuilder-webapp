<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\ChatHistory;

use Closure;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;
use Override;

/**
 * A ChatHistory implementation that:
 * 1. Can be pre-loaded with messages from a previous conversation
 * 2. Notifies via callback when new messages are added (for persistence)
 *
 * This allows the facade to manage conversation history without directly
 * depending on database entities.
 */
class CallbackChatHistory extends AbstractChatHistory
{
    /**
     * @var Closure(Message): void|null
     */
    private ?Closure $onNewMessage = null;

    /**
     * @param list<Message> $initialMessages Messages from previous conversation turns
     * @param int           $contextWindow   Maximum token count for context window
     */
    public function __construct(
        array $initialMessages = [],
        int   $contextWindow = 50000
    ) {
        parent::__construct($contextWindow);
        $this->history = $initialMessages;
    }

    /**
     * Set a callback that will be called when a new message is added.
     * The callback receives the Message object.
     *
     * @param Closure(Message): void $callback
     */
    public function setOnNewMessageCallback(Closure $callback): self
    {
        $this->onNewMessage = $callback;

        return $this;
    }

    /**
     * Called when a new message is added to the history.
     * We use this hook to notify the callback.
     */
    protected function onNewMessage(Message $message): void
    {
        if ($this->onNewMessage !== null) {
            ($this->onNewMessage)($message);
        }
    }

    /**
     * Required by AbstractChatHistory.
     * Since we manage persistence externally via callback, this is a no-op.
     *
     * @param list<Message> $messages
     */
    #[Override]
    public function setMessages(array $messages): ChatHistoryInterface
    {
        // Persistence is handled externally via callback
        return $this;
    }

    /**
     * Required by AbstractChatHistory.
     * Since we don't manage a persistent store, this is a no-op.
     */
    protected function clear(): ChatHistoryInterface
    {
        // Persistence is handled externally
        return $this;
    }
}
