<?php

declare(strict_types=1);

namespace App\ProjectMgmt\Api\Dto;

final readonly class ProjectNameDto
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
