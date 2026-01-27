<?php

declare(strict_types=1);

namespace App\CursorAgentContentEditor\Infrastructure\Observer;

use JsonException;
use SplObserver;
use SplSubject;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleObserver implements SplObserver
{
    public function __construct(
        private OutputInterface $output
    ) {
    }

    private string $buffer          = '';
    private string $thinkingBuffer  = '';
    private string $assistantBuffer = '';

    /**
     * @var array<string, array{name: string, args: array<string, mixed>}>
     */
    private array $toolCalls = [];

    public function __invoke(string $buffer, bool $isError): void
    {
        if ($this->output->isQuiet()) {
            return;
        }

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
                $decoded    = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $normalized = $this->normalizeEvent($decoded);
                if ($normalized === null) {
                    $this->output->writeln($line);
                    continue;
                }

                $this->handleEvent($normalized);
            } catch (JsonException) {
                $this->output->writeln($line);
            }
        }
    }

    public function update(SplSubject $subject): void
    {
        if (!$this->output->isVerbose()) {
            return;
        }

        $this->output->writeln(sprintf(
            '<comment>Agent event received from %s</comment>',
            $subject::class
        ));
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

        if ($type !== 'thinking') {
            $this->flushThinking();
        }

        if ($type === 'thinking') {
            $this->handleThinking($event, $subtype);

            return;
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

    /**
     * @param array<string, mixed> $event
     */
    private function handleThinking(array $event, ?string $subtype): void
    {
        if ($subtype === 'delta') {
            $text = $event['text'] ?? '';
            if (is_string($text)) {
                $this->thinkingBuffer .= $text;
            }

            return;
        }

        if ($subtype === 'completed') {
            $this->flushThinking();
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private function handleToolCall(array $event, ?string $subtype): void
    {
        $callId   = $event['call_id']   ?? null;
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

        if ($subtype === 'started' && is_string($callId)) {
            $args                     = $this->normalizeArgs($toolPayload['args'] ?? null);
            $this->toolCalls[$callId] = [
                'name' => $toolName,
                'args' => $args,
            ];

            $this->output->writeln(sprintf('▶ Calling tool: %s', $toolName));

            foreach ($this->toolCalls[$callId]['args'] as $key => $value) {
                $this->output->writeln(sprintf('    %s: %s', (string) $key, $this->formatValue($value)));
            }

            return;
        }

        if ($subtype === 'completed') {
            $args = $this->normalizeArgs($toolPayload['args'] ?? null);
            if (is_string($callId) && !array_key_exists($callId, $this->toolCalls)) {
                $this->toolCalls[$callId] = [
                    'name' => $toolName,
                    'args' => $args,
                ];
            }

            $result  = $toolPayload['result'] ?? null;
            $summary = $this->formatToolResult($result);
            $this->output->writeln(sprintf('◀ Tool result: %s', $summary));

            return;
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
                $this->assistantBuffer .= $item['text'];
            }
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private function handleResult(array $event, ?string $subtype): void
    {
        if ($subtype !== 'success') {
            return;
        }

        $result = $event['result'] ?? null;
        if (!is_string($result) || $result === '') {
            $result = $this->assistantBuffer;
        }

        if ($result !== '') {
            $this->output->writeln($result);
        }

        $this->assistantBuffer = '';
    }

    private function flushThinking(): void
    {
        $text = trim($this->thinkingBuffer);
        if ($text === '') {
            $this->thinkingBuffer = '';

            return;
        }

        $this->output->writeln(sprintf('▶ Thinking: %s', $text));
        $this->thinkingBuffer = '';
    }

    private function formatToolResult(mixed $result): string
    {
        if (!is_array($result)) {
            return $this->formatValue($result);
        }

        $success = $result['success'] ?? null;
        if (is_array($success)) {
            $root = $success['directoryTreeRoot'] ?? null;
            if (is_array($root)) {
                $files = $this->collectNames($root['childrenFiles'] ?? null);
                $dirs  = $this->collectNames($root['childrenDirs'] ?? null);

                if (count($dirs) === 0 && count($files) === 1) {
                    return $files[0];
                }

                $parts = [];
                if (count($dirs) > 0) {
                    $parts[] = 'dirs: ' . implode(', ', $dirs);
                }
                if (count($files) > 0) {
                    $parts[] = 'files: ' . implode(', ', $files);
                }

                if (count($parts) > 0) {
                    return implode('; ', $parts);
                }
            }
        }

        return $this->formatValue($result);
    }

    /**
     * @param array<int, mixed>|mixed $items
     *
     * @return list<string>
     */
    private function collectNames(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $names = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = $item['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
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

    private function truncate(string $value, int $maxLength = 200): string
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

    /**
     * @return array<string, mixed>
     */
    private function normalizeArgs(mixed $args): array
    {
        if (!is_array($args)) {
            return [];
        }

        $normalized = [];
        foreach ($args as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }
}
