<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Domain\Dto;

/**
 * DTO representing a single image prompt result from the LLM.
 */
final readonly class ImagePromptResultDto
{
    public function __construct(
        public string $prompt,
        public string $fileName,
    ) {
    }
}
