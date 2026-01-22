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
}
