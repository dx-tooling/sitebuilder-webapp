<?php

declare(strict_types=1);

namespace App\DockerIntegration\Facade\Dto;

readonly class ExecutionResultDto
{
    public function __construct(
        public int    $exitCode,
        public string $stdout,
        public string $stderr,
        public bool   $timedOut,
    ) {
    }
}
