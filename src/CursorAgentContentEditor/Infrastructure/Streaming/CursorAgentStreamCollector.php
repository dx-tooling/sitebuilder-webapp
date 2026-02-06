<?php

declare(strict_types=1);

namespace App\CursorAgentContentEditor\Infrastructure\Streaming;

use App\LlmContentEditor\Facade\Dto\AgentEventDto;
use App\LlmContentEditor\Facade\Dto\EditStreamChunkDto;
use App\LlmContentEditor\Facade\Dto\ToolInputEntryDto;
use JsonException;
use SplQueue;

use function mb_strlen;
use function mb_substr;

final class CursorAgentStreamCollector
{
    private string $buffer = '';

    /**
     * @var SplQueue<EditStreamChunkDto>
     */
    private SplQueue $chunks;

    private bool $thinkingStarted = false;

    private bool $resultSuccess = true;

    private ?string $resultErrorMessage = null;

    private ?string $lastSessionId = null;

    private string $assistantBuffer = '';
    private bool $hasEmittedText    = false;

    /**
     * Tracks the last full incoming message (before delta extraction) for dedup.
     */
    private string $lastFullMessage = '';

    /**
     * Tracks all full messages we've seen to detect repeats.
     *
     * @var list<string>
     */
    private array $seenMessages = [];

    public function __construct()
    {
        /** @var SplQueue<EditStreamChunkDto> $queue */
        $queue        = new SplQueue();
        $this->chunks = $queue;
    }

    public function __invoke(string $buffer, bool $isError): void
    {
        $this->buffer .= $buffer;

        while (true) {
            $newlinePosition = strpos($this->buffer, "\n");
            if ($newlinePosition === false) {
                return;
            }

            $line         = trim(substr($this->buffer, 0, $newlinePosition));
            $this->buffer = substr($this->buffer, $newlinePosition + 1);

            if ($line === '') {
                continue;
            }

            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            $normalized = $this->normalizeEvent($decoded);
            if ($normalized === null) {
                continue;
            }

            $this->captureSessionId($normalized);
            $this->handleEvent($normalized);
        }
    }

    /**
     * @return list<EditStreamChunkDto>
     */
    public function drain(): array
    {
        $drained = [];

        while (!$this->chunks->isEmpty()) {
            $drained[] = $this->chunks->dequeue();
        }

        return $drained;
    }

    public function getLastSessionId(): ?string
    {
        return $this->lastSessionId;
    }

    public function isSuccess(): bool
    {
        return $this->resultSuccess;
    }

    public function getErrorMessage(): ?string
    {
        return $this->resultErrorMessage;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function handleEvent(array $event): void
    {
        $type    = $event['type']    ?? null;
        $subtype = $event['subtype'] ?? null;

        if (!is_string($type)) {
            return;
        }

        if (!is_string($subtype)) {
            $subtype = null;
        }

        if ($type === 'thinking') {
            $this->handleThinking($subtype);

            return;
        }

        if ($this->thinkingStarted) {
            $this->flushThinking();
        }

        if ($type === 'tool_call') {
            $this->handleToolCall($event, $subtype);

            return;
        }

        if ($type === 'assistant') {
            $this->handleAssistant($event);

            return;
        }

        if ($type === 'result') {
            $this->handleResult($event, $subtype);
        }
    }

    private function handleThinking(?string $subtype): void
    {
        if ($subtype === 'delta') {
            if (!$this->thinkingStarted) {
                $this->thinkingStarted = true;
            }

            return;
        }

        if ($subtype === 'completed') {
            $this->flushThinking();
        }
    }

    private function flushThinking(): void
    {
        if (!$this->thinkingStarted) {
            return;
        }

        $this->thinkingStarted = false;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function handleToolCall(array $event, ?string $subtype): void
    {
        $toolCall = $event['tool_call'] ?? null;
        if (!is_array($toolCall)) {
            return;
        }

        $toolName = array_key_first($toolCall);
        if (!is_string($toolName)) {
            return;
        }

        $toolPayload = $toolCall[$toolName] ?? [];
        if (!is_array($toolPayload)) {
            $toolPayload = [];
        }

        $toolInputs = $this->buildToolInputs($toolPayload['args'] ?? null);

        if ($subtype === 'started') {
            $this->enqueueEvent(new AgentEventDto('tool_calling', $toolName, $toolInputs));

            return;
        }

        if ($subtype === 'completed') {
            $toolResult = $this->formatValue($toolPayload['result'] ?? null);
            $this->enqueueEvent(new AgentEventDto('tool_called', $toolName, $toolInputs, $toolResult));
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private function handleAssistant(array $event): void
    {
        $message = $event['message'] ?? null;
        if (!is_array($message)) {
            return;
        }

        $content = $message['content'] ?? null;
        if (!is_array($content)) {
            return;
        }

        foreach ($content as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (($item['type'] ?? null) === 'text' && is_string($item['text'] ?? null)) {
                $fullText = trim($item['text']);
                if ($fullText === '') {
                    continue;
                }

                // Check if this full message (or very similar) was already seen
                if ($this->isMessageAlreadySeen($fullText)) {
                    // Update buffer for delta tracking but don't emit
                    $this->assistantBuffer = $fullText;

                    continue;
                }

                // Extract delta from cumulative text
                $delta = $this->resolveAssistantDelta($fullText);
                if (trim($delta) === '') {
                    continue;
                }

                // Add paragraph break if we already have content and this is a new sentence
                $trimmedDelta = trim($delta);
                if ($this->hasEmittedText && $this->shouldAddParagraphBreak($trimmedDelta)) {
                    $this->chunks->enqueue(new EditStreamChunkDto('text', "\n\n"));
                }

                $this->hasEmittedText  = true;
                $this->lastFullMessage = $fullText;
                $this->recordSeenMessage($fullText);
                $this->chunks->enqueue(new EditStreamChunkDto('text', $delta));
            }
        }
    }

    /**
     * Check if a message (or very similar) was already seen.
     */
    private function isMessageAlreadySeen(string $message): bool
    {
        // Check against last full message first (most common case)
        if ($this->isSimilarToMessage($message, $this->lastFullMessage)) {
            return true;
        }

        // Check against all seen messages for exact or near duplicates
        foreach ($this->seenMessages as $seen) {
            if ($this->isSimilarToMessage($message, $seen)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if two messages are similar enough to be considered duplicates.
     */
    private function isSimilarToMessage(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }

        // Exact match
        if ($a === $b) {
            return true;
        }

        // One is a prefix of the other (cumulative update - the longer one is the "real" message)
        if (str_starts_with($a, $b) || str_starts_with($b, $a)) {
            return true;
        }

        // For messages of similar length, check similarity
        $lenA = mb_strlen($a);
        $lenB = mb_strlen($b);

        // Only check similarity for messages of similar length (within 20%)
        if (min($lenA, $lenB) / max($lenA, $lenB) < 0.8) {
            return false;
        }

        // Use levenshtein for short texts
        if ($lenA <= 255 && $lenB <= 255) {
            $distance   = levenshtein($a, $b);
            $similarity = 1 - ($distance / max($lenA, $lenB));

            return $similarity > 0.85;
        }

        // For longer texts, check if they share a long common prefix
        $commonLen = 0;
        $minLen    = min($lenA, $lenB);
        for ($i = 0; $i < $minLen; ++$i) {
            if ($a[$i] !== $b[$i]) {
                break;
            }
            ++$commonLen;
        }

        return $commonLen / $minLen > 0.85;
    }

    /**
     * Record a message as seen (keep only recent messages to limit memory).
     */
    private function recordSeenMessage(string $message): void
    {
        // Only record substantial messages
        if (mb_strlen($message) < 20) {
            return;
        }

        $this->seenMessages[] = $message;

        // Keep only the last 20 messages to limit memory
        if (count($this->seenMessages) > 20) {
            array_shift($this->seenMessages);
        }
    }

    /**
     * Determine if we should add a paragraph break before this text.
     */
    private function shouldAddParagraphBreak(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        // Add paragraph if the new text starts with a capital letter (new sentence)
        $firstChar = mb_substr($text, 0, 1);

        return preg_match('/[A-Z]/', $firstChar) === 1;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function handleResult(array $event, ?string $subtype): void
    {
        if ($subtype === 'success') {
            $this->resultSuccess      = true;
            $this->resultErrorMessage = null;

            // Only emit result text if NO text was streamed during the session.
            // The result is a complete summary that duplicates the streamed content.
            $result = $event['result'] ?? null;
            if (!$this->hasEmittedText && is_string($result) && $result !== '') {
                $this->chunks->enqueue(new EditStreamChunkDto('text', $result));
            }

            return;
        }

        $this->resultSuccess = false;
        $errorMessage        = $event['error'] ?? $event['message'] ?? null;
        if (!is_string($errorMessage) || $errorMessage === '') {
            $errorMessage = 'Cursor agent failed.';
        }

        $this->resultErrorMessage = $errorMessage;
        $this->enqueueEvent(new AgentEventDto('agent_error', null, null, null, $errorMessage));
    }

    /**
     * @param array<string, mixed> $event
     */
    private function captureSessionId(array $event): void
    {
        $sessionId = $event['session_id'] ?? $event['sessionId'] ?? null;
        if (is_string($sessionId) && $sessionId !== '') {
            $this->lastSessionId = $sessionId;
        }

        $session = $event['session'] ?? null;
        if (is_array($session)) {
            $nestedSessionId = $session['id'] ?? $session['session_id'] ?? null;
            if (is_string($nestedSessionId) && $nestedSessionId !== '') {
                $this->lastSessionId = $nestedSessionId;
            }
        }
    }

    private function enqueueEvent(AgentEventDto $event): void
    {
        $this->chunks->enqueue(new EditStreamChunkDto('event', null, $event));
    }

    /**
     * @return list<ToolInputEntryDto>|null
     */
    private function buildToolInputs(mixed $args): ?array
    {
        if (!is_array($args)) {
            return null;
        }

        $inputs = [];
        foreach ($args as $key => $value) {
            $inputs[] = new ToolInputEntryDto((string) $key, $this->formatValue($value));
        }

        return $inputs === [] ? null : $inputs;
    }

    private function formatValue(mixed $value): string
    {
        if (is_string($value)) {
            return $this->truncate($value);
        }

        if (is_scalar($value)) {
            return $this->truncate((string) $value);
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return '[unserializable]';
        }

        return $this->truncate($encoded);
    }

    private function truncate(string $value, int $maxLength = 500): string
    {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength) . '...';
    }

    /**
     * Resolve the delta from cumulative assistant text.
     *
     * The Cursor agent streams assistant messages cumulatively - each message
     * contains all text so far, not just the new part. We need to extract
     * only the new text (delta) to avoid duplicates.
     */
    private function resolveAssistantDelta(string $incoming): string
    {
        if ($incoming === '') {
            return '';
        }

        // If incoming starts with the buffer, extract just the new part
        if ($this->assistantBuffer !== '' && str_starts_with($incoming, $this->assistantBuffer)) {
            $delta                 = substr($incoming, strlen($this->assistantBuffer));
            $this->assistantBuffer = $incoming;

            return $delta;
        }

        // Check if the buffer ends with the beginning of incoming (overlap case)
        if ($this->assistantBuffer !== '') {
            $overlap = $this->findOverlap($this->assistantBuffer, $incoming);
            if ($overlap > 0) {
                $delta                 = substr($incoming, $overlap);
                $this->assistantBuffer = $this->assistantBuffer . $delta;

                return $delta;
            }
        }

        // Check if this exact text was already in the buffer (complete duplicate)
        if (str_contains($this->assistantBuffer, $incoming)) {
            return '';
        }

        // New independent text segment - update buffer and return
        $this->assistantBuffer = $incoming;

        return $incoming;
    }

    /**
     * Find how many characters of $suffix's beginning overlap with $prefix's end.
     */
    private function findOverlap(string $prefix, string $suffix): int
    {
        $maxCheck = min(strlen($prefix), strlen($suffix));

        for ($len = $maxCheck; $len > 0; --$len) {
            $prefixEnd   = substr($prefix, -$len);
            $suffixStart = substr($suffix, 0, $len);
            if ($prefixEnd === $suffixStart) {
                return $len;
            }
        }

        return 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeEvent(mixed $decoded): ?array
    {
        if (!is_array($decoded)) {
            return null;
        }

        $normalized = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
