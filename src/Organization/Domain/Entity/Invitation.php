<?php

declare(strict_types=1);

namespace App\Organization\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;
use Exception;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;

#[ORM\Entity]
#[ORM\Table(name: 'organization_invitations')]
class Invitation implements OrganizationOwnedEntityInterface
{
    /**
     * @throws Exception
     */
    public function __construct(
        Organization $organization,
        string       $email
    ) {
        $this->organization = $organization;
        $this->email        = $email;
        $this->createdAt    = DateAndTimeService::getDateTimeImmutable();
    }

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column(
        type: Types::GUID,
        unique: true
    )]
    private ?string $id = null;

    public function getId(): ?string
    {
        return $this->id;
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
    private readonly string $email;

    public function getEmail(): string
    {
        return $this->email;
    }

    #[ORM\Column(
        type: Types::DATE_IMMUTABLE,
        nullable: false
    )]
    private DateTimeImmutable $createdAt;

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
