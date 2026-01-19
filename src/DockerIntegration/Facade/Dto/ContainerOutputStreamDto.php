<?php

declare(strict_types=1);

namespace App\DockerIntegration\Facade\Dto;

readonly class ContainerOutputStreamDto
{
    public function __construct(
        public string $streamId,
        public string $containerId,
    ) {
    }
}
