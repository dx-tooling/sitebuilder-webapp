<?php

declare(strict_types=1);

namespace App\Organization\Domain\Entity;

/**
 * Interface for entities that belong to an Organization.
 *
 * This interface intentionally only exposes the organization ID, not the Organization entity itself.
 * This ensures clean separation between verticals - code outside the Organization namespace
 * should not have direct access to the Organization entity.
 *
 * Entities implementing this interface may have their own getOrganization() method
 * for internal use within the Organization namespace.
 */
interface OrganizationOwnedEntityInterface
{
    public function getId(): ?string;

    public function getOrganizationId(): string;
}
