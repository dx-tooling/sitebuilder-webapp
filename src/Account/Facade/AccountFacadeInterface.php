<?php

declare(strict_types=1);

namespace App\Account\Facade;

use App\Account\Facade\Dto\AccountInfoDto;

interface AccountFacadeInterface
{
    public function getAccountInfoById(string $id): ?AccountInfoDto;

    /**
     * Get account info by email (user identifier).
     * This is useful when you only have the UserInterface which returns email from getUserIdentifier().
     */
    public function getAccountInfoByEmail(string $email): ?AccountInfoDto;

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
}
