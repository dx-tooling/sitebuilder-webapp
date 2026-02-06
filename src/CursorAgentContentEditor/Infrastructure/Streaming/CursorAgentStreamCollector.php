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

    /**
     * Accumulates streaming parts with spaces between them.
     */
    private string $accumulatedParts = '';

    /**
     * Whether we've emitted any complete messages yet.
     */
    private bool $hasEmittedText = false;

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
     * Handle assistant text messages.
     *
     * The Cursor agent streams messages as follows:
     * 1. Individual word/phrase parts arrive WITH their natural spacing (leading/trailing spaces)
     * 2. At the end, the COMPLETE message arrives with the full properly-formatted text
     *
     * Strategy:
     * - Accumulate incoming parts by direct concatenation (preserving their natural spacing)
     * - When a new part matches the accumulated string, it's the complete message
     * - Emit the complete message and reset for the next paragraph
     *
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
                // Preserve original spacing - don't trim!
                $incomingText       = $item['text'];
                $trimmedIncoming    = trim($incomingText);
                $trimmedAccumulated = trim($this->accumulatedParts);

                if ($trimmedIncoming === '') {
                    continue;
                }

                // Check if this incoming text matches our accumulated parts (complete message arrived)
                if ($trimmedAccumulated !== '' && $this->isCompleteMessage($trimmedIncoming, $trimmedAccumulated)) {
                    // Add paragraph break if we already have content
                    if ($this->hasEmittedText) {
                        $this->chunks->enqueue(new EditStreamChunkDto('text', "\n\n"));
                    }

                    // Emit the complete message (use the trimmed incoming text as it has proper spacing)
                    $this->chunks->enqueue(new EditStreamChunkDto('text', $trimmedIncoming));
                    $this->hasEmittedText   = true;
                    $this->accumulatedParts = '';

                    continue;
                }

                // This is a streaming part - accumulate by direct concatenation (preserves natural spacing)
                $this->accumulatedParts .= $incomingText;
            }
        }
    }

    /**
     * Check if the incoming text represents the complete version of accumulated parts.
     *
     * The complete message has proper spacing between words, while our accumulated
     * parts are joined with single spaces. We compare them with normalized whitespace.
     */
    private function isCompleteMessage(string $incoming, string $accumulated): bool
    {
        // Normalize whitespace for comparison
        $normalizedIncoming    = preg_replace('/\s+/', ' ', $incoming)    ?? $incoming;
        $normalizedAccumulated = preg_replace('/\s+/', ' ', $accumulated) ?? $accumulated;

        // Exact match after normalization
        if ($normalizedIncoming === $normalizedAccumulated) {
            return true;
        }

        // Check similarity (allow for minor differences like punctuation spacing)
        $lenIncoming    = mb_strlen($normalizedIncoming);
        $lenAccumulated = mb_strlen($normalizedAccumulated);

        // Must be similar length (within 10%)
        if ($lenIncoming === 0 || $lenAccumulated === 0) {
            return false;
        }

        $lengthRatio = min($lenIncoming, $lenAccumulated) / max($lenIncoming, $lenAccumulated);
        if ($lengthRatio < 0.9) {
            return false;
        }

        // For reasonably sized strings, use levenshtein
        if ($lenIncoming <= 255 && $lenAccumulated <= 255) {
            $distance   = levenshtein($normalizedIncoming, $normalizedAccumulated);
            $similarity = 1 - ($distance / max($lenIncoming, $lenAccumulated));

            return $similarity > 0.9;
        }

        // For longer strings, check prefix similarity
        $minLen    = min($lenIncoming, $lenAccumulated);
        $commonLen = 0;
        for ($i = 0; $i < $minLen; ++$i) {
            if ($normalizedIncoming[$i] !== $normalizedAccumulated[$i]) {
                break;
            }
            ++$commonLen;
        }

        return $commonLen / $minLen > 0.9;
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
