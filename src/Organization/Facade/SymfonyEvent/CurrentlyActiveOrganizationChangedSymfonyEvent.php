<?php

declare(strict_types=1);

namespace App\Organization\Facade\SymfonyEvent;

readonly class CurrentlyActiveOrganizationChangedSymfonyEvent
{
    public function __construct(
        public string $organizationId,
        public string $affectedUserId
    ) {
    }
}
