<?php

declare(strict_types=1);

namespace App\Common\Domain\Security;

use DateTimeImmutable;
use Deprecated;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Security boundary object for authenticated users.
 *
 * This class wraps user authentication data and serves as the boundary
 * between Symfony's security system and application controllers.
 * It prevents other verticals from depending on the AccountCore entity.
 */
readonly class SecurityUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    /**
     * @param non-empty-string $id
     * @param non-empty-string $email
     * @param list<string>     $roles
     * @param non-empty-string $passwordHash
     */
    public function __construct(
        private string            $id,
        private string            $email,
        private array             $roles,
        private string            $passwordHash,
        private bool              $mustSetPassword,
        private DateTimeImmutable $createdAt,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    public function getMustSetPassword(): bool
    {
        return $this->mustSetPassword;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    #[Deprecated]
    public function eraseCredentials(): void
    {
        // SecurityUser is immutable; no temporary credentials to erase
    }
}
