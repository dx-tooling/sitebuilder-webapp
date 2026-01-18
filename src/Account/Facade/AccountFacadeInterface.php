<?php

declare(strict_types=1);

namespace App\Account\Facade;

use App\Account\Facade\Dto\AccountInfoDto;

interface AccountFacadeInterface
{
    public function getAccountInfoById(string $id): ?AccountInfoDto;
}
