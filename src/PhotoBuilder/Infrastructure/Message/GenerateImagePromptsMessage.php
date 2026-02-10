<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Infrastructure\Message;

/**
 * Dispatched to trigger async generation of image prompts for a photo session.
 */
final readonly class GenerateImagePromptsMessage
{
    public function __construct(
        public string $sessionId,
        public string $locale,
    ) {
    }
}
