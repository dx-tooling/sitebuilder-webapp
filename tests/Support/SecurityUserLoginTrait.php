<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Account\Domain\Entity\AccountCore;
use App\Account\Infrastructure\Security\SecurityUserProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Trait for tests that need to log in users.
 *
 * Provides a helper method to convert AccountCore entities to SecurityUser
 * before login, ensuring the correct user class is stored in the session.
 */
trait SecurityUserLoginTrait
{
    /**
     * Log in as the given account using SecurityUser.
     *
     * This converts the AccountCore entity to a SecurityUser before login,
     * ensuring the session contains the correct user class.
     */
    protected function loginAsUser(KernelBrowser $client, AccountCore $account): void
    {
        /** @var SecurityUserProvider $provider */
        $provider = static::getContainer()->get(SecurityUserProvider::class);

        $securityUser = $provider->loadUserByIdentifier($account->getEmail());
        $client->loginUser($securityUser);
    }
}
