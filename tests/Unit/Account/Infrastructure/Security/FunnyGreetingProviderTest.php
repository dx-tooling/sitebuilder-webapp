<?php

declare(strict_types=1);

namespace App\Tests\Unit\Account\Infrastructure\Security;

use App\Account\Infrastructure\Security\FunnyGreetingProvider;
use PHPUnit\Framework\TestCase;

final class FunnyGreetingProviderTest extends TestCase
{
    public function testGetAvailableGreetingKeysReturnsConfiguredKeys(): void
    {
        $provider = new FunnyGreetingProvider();

        self::assertSame([
            'auth.greeting.1',
            'auth.greeting.2',
            'auth.greeting.3',
            'auth.greeting.4',
            'auth.greeting.5',
        ], $provider->getAvailableGreetingKeys());
    }

    public function testGetRandomGreetingKeyAlwaysReturnsConfiguredKey(): void
    {
        $provider      = new FunnyGreetingProvider();
        $availableKeys = $provider->getAvailableGreetingKeys();

        for ($iteration = 0; $iteration < 100; ++$iteration) {
            self::assertContains($provider->getRandomGreetingKey(), $availableKeys);
        }
    }
}
