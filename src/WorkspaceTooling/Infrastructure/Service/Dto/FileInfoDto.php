<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Infrastructure\Service\Dto;

final readonly class FileInfoDto
{
    public function __construct(
        public string $path,
        public int    $lineCount,
        public int    $sizeBytes,
        public string $extension
    ) {
    }

    public function toString(): string
    {
        return sprintf(
            "File: %s\nLines: %d\nSize: %d bytes\nExtension: %s",
            $this->path,
            $this->lineCount,
            $this->sizeBytes,
            $this->extension
        );
    }
}
