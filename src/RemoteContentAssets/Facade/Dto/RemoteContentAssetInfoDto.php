<?php

declare(strict_types=1);

namespace App\RemoteContentAssets\Facade\Dto;

/**
 * Information about a remote content asset (e.g. image).
 * All fields except url may be null when unknown or unavailable.
 */
final readonly class RemoteContentAssetInfoDto
{
    public function __construct(
        public string  $url,
        public ?int    $width = null,
        public ?int    $height = null,
        public ?string $mimeType = null,
        public ?int    $sizeInBytes = null,
    ) {
    }
}
