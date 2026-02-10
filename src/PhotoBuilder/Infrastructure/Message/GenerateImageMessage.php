<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Infrastructure\Message;

/**
 * Dispatched to trigger async generation of a single image.
 */
final readonly class GenerateImageMessage
{
    public function __construct(
        public string $imageId,
    ) {
    }
}
