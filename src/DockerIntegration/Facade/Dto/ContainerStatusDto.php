<?php

declare(strict_types=1);

namespace App\DockerIntegration\Facade\Dto;

use DateTimeImmutable;

readonly class ContainerStatusDto
{
    public function __construct(
        public string             $containerId,
        public string             $state,
        public string             $health,
        public ?DateTimeImmutable $startedAt,
    ) {
    }
}
