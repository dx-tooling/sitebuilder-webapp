<?php

declare(strict_types=1);

namespace App\DockerIntegration\Facade\Dto;

use DateTimeImmutable;

readonly class ContainerDto
{
    public function __construct(
        public string            $id,
        public string            $status,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
