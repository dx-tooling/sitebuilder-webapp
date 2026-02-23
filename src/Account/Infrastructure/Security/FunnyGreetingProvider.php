<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Security;

final class FunnyGreetingProvider
{
    /**
     * @return non-empty-list<string>
     */
    public function getAvailableGreetingKeys(): array
    {
        return [
            'auth.greeting.1',
            'auth.greeting.2',
            'auth.greeting.3',
            'auth.greeting.4',
            'auth.greeting.5',
        ];
    }

    public function getRandomGreetingKey(): string
    {
        $greetingKeys = $this->getAvailableGreetingKeys();
        $keyIndex     = random_int(0, count($greetingKeys) - 1);

        return $greetingKeys[$keyIndex];
    }
}
