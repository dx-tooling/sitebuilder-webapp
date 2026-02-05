<?php

declare(strict_types=1);

namespace App\Account\Facade\SymfonyEvent;

/**
 * Event dispatched when a new AccountCore is created.
 * Used by other verticals (e.g., Organization) to react to user registration.
 */
readonly class AccountCoreCreatedSymfonyEvent
{
    public function __construct(
        public string $accountCoreId
    ) {
    }
}
