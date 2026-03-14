<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Security;

final class FunnyGreetingProvider
{
    public const string FLASH_TYPE = 'auth_greeting';

    /**
     * @var non-empty-list<non-empty-string>
     */
    private const array GREETING_KEYS = [
        'auth.greeting.1',
        'auth.greeting.2',
        'auth.greeting.3',
        'auth.greeting.4',
        'auth.greeting.5',
    ];

    /**
     * @return non-empty-list<non-empty-string>
     */
    public function getAvailableGreetingKeys(): array
    {
        return self::GREETING_KEYS;
    }

    public function getRandomGreetingKey(): string
    {
        $greetingKeys = $this->getAvailableGreetingKeys();
        $keyIndex     = random_int(0, count($greetingKeys) - 1);

        return $greetingKeys[$keyIndex];
    }
}
