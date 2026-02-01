<?php

declare(strict_types=1);

namespace App\Organization\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tracks membership of users in organization groups.
 * Uses plain GUID for accountCoreId (cross-vertical reference to Account).
 */
#[ORM\Entity]
#[ORM\Table(name: 'organization_group_members')]
class GroupMember
{
    public function __construct(
        string $accountCoreId,
        Group  $group
    ) {
        $this->accountCoreId = $accountCoreId;
        $this->group         = $group;
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
    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(
        name: 'organization_groups_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private readonly Group $group;

    public function getGroup(): Group
    {
        return $this->group;
    }

    public function getGroupId(): string
    {
        return $this->group->getId();
    }
}
