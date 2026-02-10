<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Infrastructure\Adapter;

/**
 * Generates image prompts from a page's HTML content using an LLM.
 */
interface PromptGeneratorInterface
{
    /**
     * Generate image prompts based on page HTML content and user preferences.
     *
     * @return list<array{prompt: string, fileName: string}>
     */
    public function generatePrompts(
        string $pageHtml,
        string $userPrompt,
        string $apiKey,
        int $count,
    ): array;
}
