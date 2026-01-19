<?php

declare(strict_types=1);

namespace App\OrgManagement\Facade;

use App\OrgManagement\Facade\Dto\ApiKeysDto;
use App\OrgManagement\Facade\Dto\OrganizationDto;
use InvalidArgumentException;

interface OrgManagementFacadeInterface
{
    public function getOrganization(string $organizationId): ?OrganizationDto;

    /**
     * @return list<OrganizationDto>
     */
    public function getUserOrganizations(string $userId): array;

    public function isUserMemberOfOrganization(string $userId, string $organizationId): bool;

    public function getOrganizationApiKeys(string $organizationId): ?ApiKeysDto;

    public function getOrganizationGitHubToken(string $organizationId): ?string;

    public function getOrganizationLlmApiKey(string $organizationId): ?string;

    /**
     * @throws InvalidArgumentException if user does not have access to the organization
     */
    public function validateOrganizationAccess(string $userId, string $organizationId): void;
}
