<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Security;

use App\Account\Domain\Entity\AccountCore;
use App\Common\Domain\Security\SecurityUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Custom user provider that wraps AccountCore entities into SecurityUser objects.
 *
 * This prevents other verticals from depending on the AccountCore entity
 * by providing a clean security boundary object instead.
 *
 * @implements UserProviderInterface<SecurityUser>
 */
final readonly class SecurityUserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $account = $this->findAccountByEmail($identifier);

        if ($account === null) {
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($identifier);

            throw $exception;
        }

        return $this->toSecurityUser($account);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof SecurityUser) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $account = $this->findAccountByEmail($user->getUserIdentifier());

        if ($account === null) {
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($user->getUserIdentifier());

            throw $exception;
        }

        return $this->toSecurityUser($account);
    }

    public function supportsClass(string $class): bool
    {
        return $class === SecurityUser::class;
    }

    /**
     * Upgrades the hashed password of a user, typically after a password is found to use an old algorithm.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof SecurityUser) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $account = $this->findAccountByEmail($user->getUserIdentifier());

        if ($account === null) {
            return;
        }

        $account->setPasswordHash($newHashedPassword);
        $this->entityManager->persist($account);
        $this->entityManager->flush();
    }

    private function findAccountByEmail(string $email): ?AccountCore
    {
        return $this->entityManager->getRepository(AccountCore::class)->findOneBy(['email' => $email]);
    }

    private function toSecurityUser(AccountCore $account): SecurityUser
    {
        $id           = $account->getId();
        $email        = $account->getEmail();
        $passwordHash = $account->getPasswordHash();

        if ($id === null || $id === '') {
            throw new UserNotFoundException('Account ID must not be null or empty.');
        }

        if ($email === '') {
            throw new UserNotFoundException('Account email must not be empty.');
        }

        if ($passwordHash === '') {
            throw new UserNotFoundException('Account password hash must not be empty.');
        }

        return new SecurityUser(
            $id,
            $email,
            $account->getRoles(),
            $passwordHash,
            $account->getMustSetPassword(),
            $account->getCreatedAt(),
        );
    }
}
