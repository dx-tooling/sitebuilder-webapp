<?php

declare(strict_types=1);

namespace App\DockerIntegration\Facade\Dto;

readonly class ContainerConfigDto
{
    public function __construct(
        public string  $imageName,
        public int     $memoryLimitMb,
        public float   $cpuLimit,
        public ?string $networkMode,
    ) {
    }
}
