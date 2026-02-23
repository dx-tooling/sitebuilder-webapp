<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[AsEventListener(event: LoginSuccessEvent::class, method: 'handle')]
final readonly class LoginSuccessFunnyGreetingListener
{
    private const string MAIN_FIREWALL_NAME = 'main';

    public function __construct(
        private FunnyGreetingProvider $funnyGreetingProvider,
    ) {
    }

    public function handle(LoginSuccessEvent $event): void
    {
        if ($event->getFirewallName() !== self::MAIN_FIREWALL_NAME || $event->getPreviousToken() !== null) {
            return;
        }

        $response = $event->getResponse();
        if ($response !== null && !$response instanceof RedirectResponse) {
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

        $session->getFlashBag()->add(FunnyGreetingProvider::FLASH_TYPE, $this->funnyGreetingProvider->getRandomGreetingKey());
    }
}
