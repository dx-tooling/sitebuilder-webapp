<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Infrastructure\Adapter;

use App\PhotoBuilder\Domain\Dto\ImagePromptResultDto;

/**
 * Generates image prompts from a page's HTML content using an LLM.
 */
interface PromptGeneratorInterface
{
    /**
     * Generate image prompts based on page HTML content and user preferences.
     *
     * @return list<ImagePromptResultDto>
     */
    public function generatePrompts(
        string $pageHtml,
        string $userPrompt,
        string $apiKey,
        int    $count,
    ): array;
}
