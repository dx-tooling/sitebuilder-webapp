<?php

declare(strict_types=1);

namespace App\Organization\Facade;

use App\Organization\Domain\Service\OrganizationDomainServiceInterface;

readonly class OrganizationFacade implements OrganizationFacadeInterface
{
    public function __construct(
        private OrganizationDomainServiceInterface $organizationDomainService
    ) {
    }

    public function getOrganizationNameById(string $organizationId): ?string
    {
        $organization = $this->organizationDomainService->getOrganizationById($organizationId);

        if ($organization === null) {
            return null;
        }

        return $this->organizationDomainService->getOrganizationName($organization);
    }
}
