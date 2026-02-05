<?php

declare(strict_types=1);

namespace App\Account\Facade\Dto;

final readonly class UserRegistrationDto
{
    public function __construct(
        public string  $email,
        public ?string $plainPassword = null,
        public bool    $mustSetPassword = false
    ) {
    }
}
