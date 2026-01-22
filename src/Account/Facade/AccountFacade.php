<?php

declare(strict_types=1);

namespace App\Account\Facade;

use App\Account\Domain\Entity\AccountCore;
use App\Account\Facade\Dto\AccountInfoDto;
use Doctrine\ORM\EntityManagerInterface;

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

        return $this->toDto($account);
    }

    public function getAccountInfoByEmail(string $email): ?AccountInfoDto
    {
        $repo    = $this->entityManager->getRepository(AccountCore::class);
        $account = $repo->findOneBy(['email' => $email]);
        if (!$account instanceof AccountCore) {
            return null;
        }

        return $this->toDto($account);
    }

    private function toDto(AccountCore $account): AccountInfoDto
    {
        return new AccountInfoDto(
            $account->getId() ?? '',
            $account->getEmail(),
            $account->getRoles(),
            $account->getCreatedAt(),
        );
    }
}
