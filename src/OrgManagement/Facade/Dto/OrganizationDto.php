<?php

declare(strict_types=1);

namespace App\OrgManagement\Facade\Dto;

use DateTimeImmutable;

readonly class OrganizationDto
{
    public function __construct(
        public string            $id,
        public string            $name,
        public string            $ownerId,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
