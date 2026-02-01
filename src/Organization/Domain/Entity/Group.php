<?php

declare(strict_types=1);

namespace App\Organization\Domain\Entity;

use App\Organization\Domain\Enum\AccessRight;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;
use Exception;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;

#[ORM\Entity]
#[ORM\Table(name: 'organization_groups')]
class Group implements OrganizationOwnedEntityInterface
{
    /**
     * @param list<AccessRight> $accessRights
     *
     * @throws Exception
     */
    public function __construct(
        Organization $organization,
        string       $name,
        array        $accessRights,
        bool         $isDefaultForNewMembers
    ) {
        $this->organization           = $organization;
        $this->name                   = $name;
        $this->accessRights           = $accessRights;
        $this->isDefaultForNewMembers = $isDefaultForNewMembers;
        $this->createdAt              = DateAndTimeService::getDateTimeImmutable();
    }

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column(
        type: Types::GUID,
        unique: true
    )]
    private ?string $id = null;

    /**
     * @throws Exception
     */
    public function getId(): string
    {
        return (string)$this->id;
    }

    #[ORM\ManyToOne(
        targetEntity: Organization::class,
        cascade: ['persist']
    )]
    #[ORM\JoinColumn(
        name: 'organizations_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private readonly Organization $organization;

    public function getOrganizationId(): string
    {
        return $this->organization->getId();
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    #[ORM\Column(
        type: Types::STRING,
        length: 256,
        unique: false,
        nullable: false
    )]
    private readonly string $name;

    public function getName(): string
    {
        return $this->name;
    }

    #[ORM\Column(
        type: Types::DATE_IMMUTABLE,
        nullable: false
    )]
    private readonly DateTimeImmutable $createdAt;

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @var list<AccessRight> */
    #[ORM\Column(
        type: Types::SIMPLE_ARRAY,
        length: 1024,
        nullable: false,
        enumType: AccessRight::class
    )]
    private readonly array $accessRights;

    /**
     * @return list<AccessRight>
     */
    public function getAccessRights(): array
    {
        return $this->accessRights;
    }

    #[ORM\Column(
        type: Types::BOOLEAN,
        nullable: false
    )]
    private readonly bool $isDefaultForNewMembers;

    public function isDefaultForNewMembers(): bool
    {
        return $this->isDefaultForNewMembers;
    }

    public function isAdministratorsGroup(): bool
    {
        return $this->getName() === 'Administrators';
    }

    public function isTeamMembersGroup(): bool
    {
        return $this->getName() === 'Team Members';
    }
}
