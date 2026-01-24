<?php

declare(strict_types=1);

namespace App\Account\Domain\Entity;

use DateTimeImmutable;
use Deprecated;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;
use Exception;
use LogicException;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'account_cores')]
class AccountCore implements UserInterface, PasswordAuthenticatedUserInterface
{
    /**
     * @throws Exception
     */
    public function __construct(
        string $email,
        string $passwordHash
    ) {
        $this->email        = $email;
        $this->passwordHash = $passwordHash;
        $this->createdAt    = DateAndTimeService::getDateTimeImmutable();
        $this->roles        = ['ROLE_USER'];
    }

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column(
        type  : Types::GUID,
        unique: true
    )]
    private ?string $id = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    #[ORM\Column(
        type    : Types::DATETIME_IMMUTABLE,
        nullable: false
    )]
    private readonly DateTimeImmutable $createdAt;

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[ORM\Column(
        type  : Types::STRING,
        length: 1024,
        unique: true
    )]
    private readonly string $email;

    public function getEmail(): string
    {
        return $this->email;
    }

    #[ORM\Column(
        type  : Types::STRING,
        length: 1024
    )]
    private readonly string $passwordHash;

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    /**
     * @var list<string>
     */
    #[ORM\Column(
        type: Types::JSON
    )]
    private array $roles;

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
    }

    #[Deprecated]
    public function eraseCredentials(): void
    {
        // No temporary sensitive data stored on this user entity
    }

    public function getUserIdentifier(): string
    {
        if ($this->email === '') {
            throw new LogicException('User identifier (email) must not be empty.');
        }

        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }
}
