<?php

declare(strict_types=1);

namespace App\Organization\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tracks membership of users in organizations.
 * Uses plain GUID for accountCoreId (cross-vertical reference to Account).
 */
#[ORM\Entity]
#[ORM\Table(name: 'organization_members')]
class OrganizationMember
{
    public function __construct(
        string       $accountCoreId,
        Organization $organization
    ) {
        $this->accountCoreId = $accountCoreId;
        $this->organization  = $organization;
    }

    #[ORM\Id]
    #[ORM\Column(
        name: 'account_cores_id',
        type: Types::GUID,
        nullable: false
    )]
    private readonly string $accountCoreId;

    public function getAccountCoreId(): string
    {
        return $this->accountCoreId;
    }

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(
        name: 'organizations_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private readonly Organization $organization;

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getOrganizationId(): string
    {
        return $this->organization->getId();
    }
}
