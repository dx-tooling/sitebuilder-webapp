<?php

declare(strict_types=1);

namespace App\Account\Facade;

use App\Account\Domain\Entity\AccountCore;
use App\Account\Domain\Service\AccountDomainService;
use App\Account\Facade\Dto\AccountInfoDto;
use App\Account\Facade\Dto\ResultDto;
use App\Account\Facade\Dto\UserRegistrationDto;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

final readonly class AccountFacade implements AccountFacadeInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AccountDomainService   $accountDomainService
    ) {
    }

    public function register(UserRegistrationDto $dto): ResultDto
    {
        try {
            $accountCore = $this->accountDomainService->register(
                $dto->email,
                $dto->plainPassword,
                $dto->mustSetPassword
            );

            return new ResultDto(true, null, $accountCore->getId());
        } catch (Throwable $t) {
            return new ResultDto(false, $t->getMessage());
        }
    }

    public function mustSetPassword(string $email): bool
    {
        $account = $this->findAccountByEmail($email);

        return $account?->getMustSetPassword() ?? false;
    }

    public function getAccountInfoById(string $id): ?AccountInfoDto
    {
        $account = $this->findAccountById($id);
        if ($account === null) {
            return null;
        }

        return $this->toDto($account);
    }

    public function getAccountInfoByEmail(string $email): ?AccountInfoDto
    {
        $account = $this->findAccountByEmail($email);
        if ($account === null) {
            return null;
        }

        return $this->toDto($account);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<AccountInfoDto>
     */
    public function getAccountInfoByIds(array $ids): array
    {
        if (count($ids) === 0) {
            return [];
        }

        $repo     = $this->entityManager->getRepository(AccountCore::class);
        $accounts = $repo->findBy(['id' => $ids]);

        $result = [];
        foreach ($accounts as $account) {
            $result[] = $this->toDto($account);
        }

        return $result;
    }

    public function getAccountCoreIdByEmail(string $email): ?string
    {
        $account = $this->findAccountByEmail($email);

        return $account?->getId();
    }

    public function getAccountCoreEmailById(string $id): ?string
    {
        $account = $this->findAccountById($id);

        return $account?->getEmail();
    }

    public function accountCoreWithIdExists(string $id): bool
    {
        return $this->findAccountById($id) !== null;
    }

    public function getCurrentlyActiveOrganizationIdForAccountCore(string $userId): ?string
    {
        $account = $this->findAccountById($userId);

        return $account?->getCurrentlyActiveOrganizationId();
    }

    public function setCurrentlyActiveOrganizationId(string $userId, string $organizationId): void
    {
        $account = $this->findAccountById($userId);
        if ($account === null) {
            return;
        }

        $account->setCurrentlyActiveOrganizationId($organizationId);
        $this->entityManager->persist($account);
        $this->entityManager->flush();
    }

    public function getAccountForLogin(string $userId): ?UserInterface
    {
        return $this->findAccountById($userId);
    }

    public function getDisplayName(string $userId): string
    {
        $account = $this->findAccountById($userId);

        return $account?->getEmail() ?? 'Unknown';
    }

    private function findAccountById(string $id): ?AccountCore
    {
        $repo    = $this->entityManager->getRepository(AccountCore::class);
        $account = $repo->find($id);

        return $account instanceof AccountCore ? $account : null;
    }

    private function findAccountByEmail(string $email): ?AccountCore
    {
        $repo    = $this->entityManager->getRepository(AccountCore::class);
        $account = $repo->findOneBy(['email' => $email]);

        return $account instanceof AccountCore ? $account : null;
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
