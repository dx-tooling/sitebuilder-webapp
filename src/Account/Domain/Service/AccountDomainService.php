<?php

declare(strict_types=1);

namespace App\Account\Domain\Service;

use App\Account\Domain\Entity\AccountCore;
use App\Account\Facade\SymfonyEvent\AccountCoreCreatedSymfonyEvent;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class AccountDomainService
{
    public function __construct(
        private EntityManagerInterface      $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private EventDispatcherInterface    $eventDispatcher
    ) {
    }

    public function register(
        string  $email,
        ?string $plainPassword = null,
        bool    $mustSetPassword = false
    ): AccountCore {
        $email = trim(mb_strtolower($email));

        // Prevent duplicate accounts by email
        $existing = $this->findByEmail($email);
        if ($existing !== null) {
            throw new LogicException('An account with this email already exists.');
        }

        // If no password provided, generate a random one
        if ($plainPassword === null) {
            $plainPassword = (string) random_int(PHP_INT_MIN, PHP_INT_MAX);
        }

        // Create a temporary AccountCore to satisfy the hasher's interface
        $tempAccount    = new AccountCore($email, '');
        $hashedPassword = $this->passwordHasher->hashPassword($tempAccount, $plainPassword);
        $account        = new AccountCore($email, $hashedPassword);
        $account->setMustSetPassword($mustSetPassword);

        $this->entityManager->persist($account);
        $this->entityManager->flush();

        // Dispatch event for other verticals (e.g., Organization creation)
        $accountId = $account->getId();
        if ($accountId !== null) {
            $this->eventDispatcher->dispatch(new AccountCoreCreatedSymfonyEvent($accountId));
        }

        return $account;
    }

    public function updatePassword(AccountCore $account, string $plainPassword): void
    {
        $hashedPassword = $this->passwordHasher->hashPassword($account, $plainPassword);
        $account->setPasswordHash($hashedPassword);
        $this->entityManager->persist($account);
        $this->entityManager->flush();
    }

    public function findByEmail(string $email): ?AccountCore
    {
        return $this->entityManager->getRepository(AccountCore::class)->findOneBy(['email' => $email]);
    }

    public function verifyPassword(AccountCore $account, string $plainPassword): bool
    {
        return $this->passwordHasher->isPasswordValid($account, $plainPassword);
    }
}
