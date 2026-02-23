<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[AsEventListener(event: LoginSuccessEvent::class, method: 'handle')]
final readonly class LoginSuccessFunnyGreetingListener
{
    public function __construct(
        private FunnyGreetingProvider $funnyGreetingProvider,
    ) {
    }

    public function handle(LoginSuccessEvent $event): void
    {
        if ($event->getFirewallName() !== 'main' || $event->getPreviousToken() !== null) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            return;
        }

        $session->getFlashBag()->add('auth_greeting', $this->funnyGreetingProvider->getRandomGreetingKey());
    }
}
