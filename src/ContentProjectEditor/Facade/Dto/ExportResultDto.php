<?php

declare(strict_types=1);

namespace App\ContentProjectEditor\Facade\Dto;

use DateTimeImmutable;

readonly class ExportResultDto
{
    public function __construct(
        public string            $downloadUrl,
        public string            $fileName,
        public int               $fileSizeBytes,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
