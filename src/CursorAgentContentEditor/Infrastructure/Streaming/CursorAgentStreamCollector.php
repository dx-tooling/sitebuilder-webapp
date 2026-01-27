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
                $text  = $item['text'];
                $delta = $this->resolveAssistantDelta($text);
                if ($delta === '') {
                    continue;
                }

                $this->hasEmittedText = true;
                $this->chunks->enqueue(new EditStreamChunkDto('text', $delta));
            }
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private function handleResult(array $event, ?string $subtype): void
    {
        if ($subtype === 'success') {
            $this->resultSuccess      = true;
            $this->resultErrorMessage = null;

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

    private function resolveAssistantDelta(string $incoming): string
    {
        if ($incoming === '') {
            return '';
        }

        if ($this->assistantBuffer !== '' && str_starts_with($incoming, $this->assistantBuffer)) {
            $delta                 = substr($incoming, strlen($this->assistantBuffer));
            $this->assistantBuffer = $incoming;

            return $delta;
        }

        $this->assistantBuffer .= $incoming;

        return $incoming;
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
