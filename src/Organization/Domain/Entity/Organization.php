<?php

declare(strict_types=1);

namespace App\Organization\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;

#[ORM\Entity]
#[ORM\Table(name: 'organizations')]
class Organization
{
    public function __construct(
        string $owningUsersId
    ) {
        $this->owningUsersId = $owningUsersId;
    }

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column(
        type: Types::GUID,
        unique: true
    )]
    private ?string $id = null;

    public function getId(): string
    {
        return (string)$this->id;
    }

    #[ORM\Column(
        type: Types::GUID,
        nullable: false
    )]
    private readonly string $owningUsersId;

    public function getOwningUsersId(): string
    {
        return $this->owningUsersId;
    }

    #[ORM\Column(
        type: Types::STRING,
        length: 256,
        unique: false,
        nullable: true
    )]
    private ?string $name = null;

    public function setName(?string $name): void
    {
        $this->name = $name !== null ? mb_substr(trim($name), 0, 256) : null;
    }

    public function getName(): ?string
    {
        return $this->name;
    }
}
