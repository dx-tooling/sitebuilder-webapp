<?php

declare(strict_types=1);

namespace App\Account\Facade;

use App\Account\Facade\Dto\AccountInfoDto;
use InvalidArgumentException;

interface AccountFacadeInterface
{
    public function getAccountInfoById(string $id): ?AccountInfoDto;

    public function getCurrentUser(): ?AccountInfoDto;

    public function isAuthenticated(): bool;

    /**
     * @throws InvalidArgumentException if user does not exist
     */
    public function validateUserExists(string $userId): void;
}
