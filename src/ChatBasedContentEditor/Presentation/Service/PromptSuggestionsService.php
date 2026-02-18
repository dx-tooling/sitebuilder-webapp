<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Presentation\Service;

use InvalidArgumentException;
use OutOfRangeException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads, creates, updates, and deletes prompt suggestions in .sitebuilder/prompt-suggestions.md.
 */
final readonly class PromptSuggestionsService
{
    private const string SUGGESTIONS_FILE_PATH = '.sitebuilder/prompt-suggestions.md';

    /**
     * Get prompt suggestions from the workspace's .sitebuilder/prompt-suggestions.md file.
     *
     * @return list<string> List of prompt suggestions (empty if file doesn't exist)
     */
    public function getSuggestions(?string $workspacePath): array
    {
        if ($workspacePath === null || !is_dir($workspacePath)) {
            return [];
        }

        $filePath = $workspacePath . '/' . self::SUGGESTIONS_FILE_PATH;

        if (!file_exists($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);

        if ($content === false) {
            return [];
        }

        // Parse each non-empty line as a suggestion
        $lines       = explode("\n", $content);
        $suggestions = array_map(
            static fn (string $line): string => trim($line),
            $lines
        );

        // Filter out empty lines and return as indexed array
        return array_values(
            array_filter(
                $suggestions,
                static fn (string $line): bool => $line !== ''
            )
        );
    }

    /**
     * Add a new prompt suggestion to the workspace's suggestions file.
     * Creates the file and directory if they don't exist.
     *
     * @return list<string> Updated list of suggestions
     */
    public function addSuggestion(string $workspacePath, string $text): array
    {
        $text = $this->sanitize($text);
        if ($text === '') {
            throw new InvalidArgumentException('Suggestion text must not be empty.');
        }

        $suggestions = $this->getSuggestions($workspacePath);

        if ($this->isDuplicate($text, $suggestions)) {
            throw new InvalidArgumentException('This suggestion already exists.');
        }

        array_unshift($suggestions, $text);

        $this->saveSuggestions($workspacePath, $suggestions);

        return $suggestions;
    }

    /**
     * Update an existing prompt suggestion at the given index.
     *
     * @return list<string> Updated list of suggestions
     */
    public function updateSuggestion(string $workspacePath, int $index, string $text): array
    {
        $text = $this->sanitize($text);
        if ($text === '') {
            throw new InvalidArgumentException('Suggestion text must not be empty.');
        }

        $suggestions = $this->getSuggestions($workspacePath);

        if ($index < 0 || $index >= count($suggestions)) {
            throw new OutOfRangeException('Suggestion index ' . $index . ' is out of range.');
        }

        if ($this->isDuplicate($text, $suggestions, $index)) {
            throw new InvalidArgumentException('This suggestion already exists.');
        }

        $suggestions[$index] = $text;
        $suggestions         = array_values($suggestions);

        $this->saveSuggestions($workspacePath, $suggestions);

        return $suggestions;
    }

    /**
     * Delete a prompt suggestion at the given index.
     *
     * @return list<string> Updated list of suggestions
     */
    public function deleteSuggestion(string $workspacePath, int $index): array
    {
        $suggestions = $this->getSuggestions($workspacePath);

        if ($index < 0 || $index >= count($suggestions)) {
            throw new OutOfRangeException('Suggestion index ' . $index . ' is out of range.');
        }

        array_splice($suggestions, $index, 1);

        $this->saveSuggestions($workspacePath, $suggestions);

        return $suggestions;
    }

    /**
     * @param list<string> $suggestions
     */
    private function isDuplicate(string $text, array $suggestions, ?int $excludeIndex = null): bool
    {
        $normalised = mb_strtolower($text);

        foreach ($suggestions as $index => $existing) {
            if ($excludeIndex !== null && $index === $excludeIndex) {
                continue;
            }

            if (mb_strtolower($existing) === $normalised) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip newlines, control characters, and collapse whitespace to produce a single-line string.
     * Suggestions are stored one per line, so embedded newlines would corrupt the file format.
     */
    private function sanitize(string $text): string
    {
        // Replace newlines with spaces
        $text = str_replace(["\r\n", "\r", "\n"], ' ', $text);

        // Remove invisible/control characters (Unicode category C) but keep regular spaces
        $text = (string) preg_replace('/[\p{C}]+/u', '', $text);

        // Collapse multiple spaces into one
        $text = (string) preg_replace('/\s{2,}/', ' ', $text);

        return trim($text);
    }

    /**
     * Write the suggestions list back to the file.
     *
     * @param list<string> $suggestions
     */
    private function saveSuggestions(string $workspacePath, array $suggestions): void
    {
        $filePath = $workspacePath . '/' . self::SUGGESTIONS_FILE_PATH;
        $dir      = dirname($filePath);

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException('Could not create directory: ' . $dir);
        }

        $content = implode("\n", $suggestions) . "\n";

        if (file_put_contents($filePath, $content) === false) {
            throw new RuntimeException('Could not write suggestions file: ' . $filePath);
        }
    }

    public function getRequestText(Request $request): ?string
    {
        $content = $request->getContent();
        if ($content === '') {
            return null;
        }

        $data = json_decode($content, true);
        if (
            !is_array($data)
            || !array_key_exists('text', $data)
            || !is_string($data['text'])
            || trim($data['text']) === ''
        ) {
            return null;
        }

        return $data['text'];
    }
}
