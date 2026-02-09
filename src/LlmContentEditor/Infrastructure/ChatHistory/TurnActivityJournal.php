<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\ChatHistory;

use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Tools\Tool;
use Throwable;

use function count;
use function implode;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function sprintf;

/**
 * Automatically records every tool call and its result within a single chat turn.
 *
 * Entries are recorded when a ToolCallResultMessage is processed (at that point,
 * each tool has its name, inputs, and result filled in). The journal formats a
 * concise numbered summary suitable for injection into the system prompt, so the
 * LLM always knows what it already did — even after context-window trimming removes
 * the actual tool-call messages from the history.
 *
 * Truncation rules:
 * - Parameter values: max 80 chars each
 * - Tool result: max 150 chars
 * - Total summary: max ~4000 chars; oldest entries are collapsed when exceeded
 */
final class TurnActivityJournal
{
    private const int MAX_PARAM_VALUE_LENGTH = 80;
    private const int MAX_RESULT_LENGTH      = 150;
    private const int MAX_SUMMARY_LENGTH     = 4000;

    /**
     * @var list<array{name: string, params: string, result: string}>
     */
    private array $entries = [];

    /**
     * Record all completed tool calls from a ToolCallResultMessage.
     * Each tool in the message has name, inputs, and result available.
     */
    public function recordToolResults(ToolCallResultMessage $message): void
    {
        foreach ($message->getTools() as $tool) {
            if (!$tool instanceof Tool) {
                continue;
            }

            // Tool::getResult() declares string return but may throw TypeError when
            // the underlying property is still null (e.g. if the tool never executed).
            try {
                $result = $tool->getResult();
            } catch (Throwable) {
                $result = '';
            }

            $this->entries[] = [
                'name'   => $tool->getName(),
                'params' => $this->formatParams($tool->getInputs()),
                'result' => $this->truncate($result, self::MAX_RESULT_LENGTH),
            ];
        }
    }

    /**
     * Return the formatted summary of all actions performed so far.
     * Empty string if no tool calls have been recorded.
     */
    public function getSummary(): string
    {
        if ($this->entries === []) {
            return '';
        }

        $totalEntries = count($this->entries);
        $lines        = [];
        $startIndex   = 0;

        // Build full summary first, then check if we need to collapse
        $fullLines = [];
        foreach ($this->entries as $i => $entry) {
            $fullLines[] = $this->formatEntry($i + 1, $entry);
        }

        $fullSummary = implode("\n", $fullLines);

        if (mb_strlen($fullSummary) <= self::MAX_SUMMARY_LENGTH) {
            return $fullSummary;
        }

        // Collapse oldest entries to fit within the limit
        // Find how many recent entries fit, and summarize the rest
        $recentLines   = [];
        $recentLength  = 0;
        $collapsedLine = '';

        for ($i = $totalEntries - 1; $i >= 0; --$i) {
            $line       = $this->formatEntry($i + 1, $this->entries[$i]);
            $lineLength = mb_strlen($line) + 1; // +1 for newline

            // Reserve space for the collapse header
            $collapseHeaderLength = mb_strlen(sprintf('(... and %d earlier actions)', $i + 1)) + 1;

            if ($recentLength + $lineLength + $collapseHeaderLength > self::MAX_SUMMARY_LENGTH) {
                $collapsedLine = sprintf('(... and %d earlier actions)', $i + 1);

                break;
            }

            array_unshift($recentLines, $line);
            $recentLength += $lineLength;
        }

        if ($collapsedLine !== '') {
            array_unshift($recentLines, $collapsedLine);
        }

        return implode("\n", $recentLines);
    }

    /**
     * Return the number of recorded entries.
     */
    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Format a single journal entry as a numbered line.
     *
     * @param array{name: string, params: string, result: string} $entry
     */
    private function formatEntry(int $number, array $entry): string
    {
        $line = sprintf('%d. [%s]', $number, $entry['name']);

        if ($entry['params'] !== '') {
            $line .= ' ' . $entry['params'];
        }

        $line .= ' → ' . ($entry['result'] !== '' ? $entry['result'] : '(no output)');

        return $line;
    }

    /**
     * Format tool input parameters as a concise key=value string.
     *
     * @param array<mixed> $inputs
     */
    private function formatParams(array $inputs): string
    {
        if ($inputs === []) {
            return '';
        }

        $parts = [];
        foreach ($inputs as $key => $value) {
            $stringValue = is_string($value) ? $value : (string) json_encode($value);
            $parts[]     = $key . '="' . $this->truncate($stringValue, self::MAX_PARAM_VALUE_LENGTH) . '"';
        }

        return implode(' ', $parts);
    }

    /**
     * Truncate a string to a maximum length, appending "..." if truncated.
     */
    private function truncate(string $value, int $maxLength): string
    {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength) . '...';
    }
}
