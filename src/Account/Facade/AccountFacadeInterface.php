<?php

declare(strict_types=1);

namespace App\Account\Facade;

use App\Account\Facade\Dto\AccountInfoDto;
use Symfony\Component\Security\Core\User\UserInterface;

interface AccountFacadeInterface
{
    public function getAccountInfoById(string $id): ?AccountInfoDto;

    /**
     * Get account info by email (user identifier).
     * This is useful when you only have the UserInterface which returns email from getUserIdentifier().
     */
    public function getAccountInfoByEmail(string $email): ?AccountInfoDto;

    /**
     * Get account info for multiple users by their IDs.
     *
     * @param list<string> $ids
     * @return list<AccountInfoDto>
     */
    public function getAccountInfoByIds(array $ids): array;

    /**
     * Get the account ID by email address.
     */
    public function getAccountCoreIdByEmail(string $email): ?string;

    /**
     * Get email address by account ID.
     */
    public function getAccountCoreEmailById(string $id): ?string;

    /**
     * Check if an account with the given ID exists.
     */
    public function accountCoreWithIdExists(string $id): bool;

    /**
     * Get the currently active organization ID for a user.
     */
    public function getCurrentlyActiveOrganizationIdForAccountCore(string $userId): ?string;

    /**
     * Set the currently active organization ID for a user.
     */
    public function setCurrentlyActiveOrganizationId(string $userId, string $organizationId): void;

    /**
     * Get the UserInterface for login purposes by account ID.
     */
    public function getAccountForLogin(string $userId): ?UserInterface;

    /**
     * Get display name for an account (email or custom display name if available).
     */
    public function getDisplayName(string $userId): string;
}
