<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\ChatHistory;

use App\LlmContentEditor\Domain\TurnNotesProviderInterface;
use App\LlmContentEditor\Facade\Dto\ConversationMessageDto;
use Closure;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;
use Override;

use function array_key_exists;
use function implode;
use function is_string;

/**
 * A ChatHistory implementation that:
 * 1. Can be pre-loaded with messages from a previous conversation
 * 2. Notifies via callback when new messages are added (for persistence)
 * 3. Accumulates write_note_to_self notes for the current turn and exposes them for the system prompt
 *
 * Notes are not injected into the message flow; they are appended to the system prompt
 * on each LLM request as "Summary of what you have done so far this turn".
 */
class CallbackChatHistory extends AbstractChatHistory implements TurnNotesProviderInterface
{
    /**
     * @var Closure(Message): void|null
     */
    private ?Closure $onNewMessage = null;

    /**
     * Notes from write_note_to_self tool calls in this turn, by tool call_id.
     * When we see the corresponding tool result, we move the note to accumulatedTurnNotes.
     *
     * @var array<string, string>
     */
    private array $pendingInTurnNotes = [];

    /**
     * All notes from write_note_to_self in this turn, in order. Exposed via getAccumulatedTurnNotes()
     * and appended to the system prompt on each API request.
     *
     * @var list<string>
     */
    private array $accumulatedTurnNotes = [];

    /**
     * Tracks the most recent UserMessage added to history.
     *
     * Used as a safety net: when aggressive context-window trimming in the
     * parent class removes ALL messages (including the UserMessage), we
     * restore this message so the LLM always sees at least the user's
     * instruction. Without this, the agent enters an infinite tool-call
     * loop because it receives only the system prompt and believes it is
     * starting a new session.
     *
     * Note: ToolCallResultMessage extends UserMessage in NeuronAI, so we
     * explicitly exclude it — only genuine user instructions are tracked.
     *
     * @see https://github.com/dx-tooling/sitebuilder-webapp/issues/75
     */
    private ?UserMessage $latestUserMessage = null;

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
     * We use this hook to:
     * 1. Track the latest genuine UserMessage for trim recovery
     * 2. Store write_note_to_self note content by call_id
     * 3. When we see the tool result, append the note(s) to accumulatedTurnNotes (for system prompt)
     * 4. Notify the persistence callback.
     */
    protected function onNewMessage(Message $message): void
    {
        if ($message instanceof UserMessage && !$message instanceof ToolCallResultMessage) {
            $this->latestUserMessage = $message;
        }

        if ($message instanceof ToolCallMessage) {
            foreach ($message->getTools() as $tool) {
                if ($tool instanceof Tool && $tool->getName() === ConversationMessageDto::TOOL_NAME_WRITE_NOTE_TO_SELF) {
                    $inputs = $tool->getInputs();
                    $note   = array_key_exists('note', $inputs) && is_string($inputs['note']) ? $inputs['note'] : '';
                    if ($note !== '') {
                        $callId                                                    = $tool->getCallId();
                        $this->pendingInTurnNotes[$callId !== null ? $callId : ''] = $note;
                    }
                }
            }
        }

        if ($message instanceof ToolCallResultMessage) {
            foreach ($message->getTools() as $tool) {
                if (!$tool instanceof Tool || $tool->getName() !== ConversationMessageDto::TOOL_NAME_WRITE_NOTE_TO_SELF) {
                    continue;
                }
                $callId = $tool->getCallId();
                $key    = $callId !== null ? $callId : '';
                if (array_key_exists($key, $this->pendingInTurnNotes)) {
                    $this->accumulatedTurnNotes[] = $this->pendingInTurnNotes[$key];
                    unset($this->pendingInTurnNotes[$key]);
                }
            }
        }

        if ($this->onNewMessage !== null) {
            ($this->onNewMessage)($message);
        }
    }

    public function getAccumulatedTurnNotes(): string
    {
        if ($this->accumulatedTurnNotes === []) {
            return '';
        }

        $lines = array_map(static fn (string $note): string => '- ' . $note, $this->accumulatedTurnNotes);

        return implode("\n", $lines);
    }

    /**
     * Override context-window trimming to prevent complete history loss.
     *
     * The parent's trimHistory() removes messages from the front of the
     * history to fit under the token limit, then runs sequence validation
     * (ensureValidMessageSequence). When all UserMessages are trimmed away,
     * the validation clears the entire history to []. This causes the LLM
     * to receive only the system prompt, leading to an infinite tool-call
     * loop (see issue #75).
     *
     * After the parent trims, if the history is empty but we have a tracked
     * UserMessage, we restore it. A single UserMessage is always a valid
     * message sequence (starts with USER role), so no further validation
     * is needed.
     */
    #[Override]
    protected function trimHistory(): int
    {
        $skipIndex = parent::trimHistory();

        if ($this->history === [] && $this->latestUserMessage !== null) {
            $this->history = [$this->latestUserMessage];
        }

        return $skipIndex;
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
