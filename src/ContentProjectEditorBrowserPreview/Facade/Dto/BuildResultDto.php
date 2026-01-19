<?php

declare(strict_types=1);

namespace App\ContentProjectEditorBrowserPreview\Facade\Dto;

readonly class BuildResultDto
{
    public function __construct(
        public bool    $success,
        public ?string $outputPath,
        public ?string $errorMessage,
        public int     $buildDurationMs,
    ) {
    }
}
