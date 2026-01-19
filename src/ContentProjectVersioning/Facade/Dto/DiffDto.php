<?php

declare(strict_types=1);

namespace App\ContentProjectVersioning\Facade\Dto;

readonly class DiffDto
{
    /**
     * @param list<string> $changedFiles
     */
    public function __construct(
        public string $versionId,
        public array  $changedFiles,
        public int    $additions,
        public int    $deletions,
    ) {
    }
}
