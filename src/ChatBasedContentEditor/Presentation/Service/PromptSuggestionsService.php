<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Presentation\Service;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function file_exists;
use function file_get_contents;
use function is_dir;
use function trim;

/**
 * Reads and parses prompt suggestions from .sitebuilder/prompt-suggestions.md.
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
}
