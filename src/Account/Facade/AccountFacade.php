<?php

declare(strict_types=1);

namespace App\Account\Facade;

use App\Account\Domain\Entity\AccountCore;
use App\Account\Facade\Dto\AccountInfoDto;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

final readonly class AccountFacade implements AccountFacadeInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function getAccountInfoById(string $id): ?AccountInfoDto
    {
        $repo    = $this->entityManager->getRepository(AccountCore::class);
        $account = $repo->find($id);
        if (!$account instanceof AccountCore) {
            return null;
        }

        return new AccountInfoDto(
            $account->getId() ?? '',
            $account->getEmail(),
            $account->getRoles(),
            $account->getCreatedAt(),
        );
    }

    public function getCurrentUser(): ?AccountInfoDto
    {
        throw new RuntimeException('Not implemented');
    }

    public function isAuthenticated(): bool
    {
        throw new RuntimeException('Not implemented');
    }

    public function validateUserExists(string $userId): void
    {
        throw new RuntimeException('Not implemented');
    }
}
