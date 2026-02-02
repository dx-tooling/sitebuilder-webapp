<?php

declare(strict_types=1);

namespace App\Organization\Facade;

use App\Organization\Domain\Enum\AccessRight;
use App\Organization\Domain\Service\OrganizationDomainServiceInterface;
use Exception;

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

    public function userCanReviewWorkspaces(string $userId): bool
    {
        try {
            return $this->organizationDomainService->userHasAccessRight(
                $userId,
                AccessRight::REVIEW_WORKSPACES
            );
        } catch (Exception) {
            // If user has no active organization, they cannot review workspaces
            return false;
        }
    }
}
