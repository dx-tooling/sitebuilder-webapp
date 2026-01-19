<?php

declare(strict_types=1);

namespace App\WorkspaceManagement\Facade\Dto;

readonly class CommandResultDto
{
    public function __construct(
        public int    $exitCode,
        public string $stdout,
        public string $stderr,
        public int    $durationMs,
    ) {
    }
}
