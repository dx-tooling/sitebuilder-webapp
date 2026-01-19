<?php

declare(strict_types=1);

namespace App\WorkspaceManagement\Facade\Dto;

readonly class FileEntryDto
{
    public function __construct(
        public string $name,
        public bool   $isDirectory,
        public int    $sizeBytes,
    ) {
    }
}
