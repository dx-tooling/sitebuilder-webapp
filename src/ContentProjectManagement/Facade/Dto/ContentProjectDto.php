<?php

declare(strict_types=1);

namespace App\ContentProjectManagement\Facade\Dto;

use DateTimeImmutable;

readonly class ContentProjectDto
{
    public function __construct(
        public string             $id,
        public string             $organizationId,
        public string             $name,
        public ?string            $gitHubRepoUrl,
        public DateTimeImmutable  $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {
    }
}
