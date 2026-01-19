<?php

declare(strict_types=1);

namespace App\WorkspaceManagement\Facade\Dto;

readonly class FileListDto
{
    /**
     * @param list<FileEntryDto> $entries
     */
    public function __construct(
        public string $path,
        public array  $entries,
    ) {
    }
}
