<?php

declare(strict_types=1);

namespace App\Organization\Facade;

interface OrganizationFacadeInterface
{
    public function getOrganizationNameById(string $organizationId): ?string;
}
