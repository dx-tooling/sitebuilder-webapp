<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Infrastructure\Dto;

/**
 * DTO representing an HTML file in the workspace dist folder.
 */
final readonly class DistFileDto
{
    public function __construct(
        public string $path,
        public string $url,
    ) {
    }
}
