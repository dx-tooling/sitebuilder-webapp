<?php

declare(strict_types=1);

namespace App\Account\Facade\Dto;

use DateTimeImmutable;

readonly class AccountInfoDto
{
    public function __construct(
        public string            $id,
        public string            $email,
        /** @var string[] */
        public array             $roles,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
