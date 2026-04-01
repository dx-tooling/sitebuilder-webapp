<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
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
        if (!$this->isHandledFirewallLogin($event)) {
            return;
        }

        $response = $event->getResponse();
        if ($response !== null && !$response instanceof RedirectResponse) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->isHtmlRequest($request) || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            return;
        }

        $flashBag = $session->getFlashBag();
        if ($flashBag->peek(FunnyGreetingProvider::FLASH_TYPE) !== []) {
            return;
        }

        $flashBag->add(FunnyGreetingProvider::FLASH_TYPE, $this->funnyGreetingProvider->getRandomGreetingKey());
    }

    private function isHandledFirewallLogin(LoginSuccessEvent $event): bool
    {
        if ($event->getFirewallName() !== self::MAIN_FIREWALL_NAME) {
            return false;
        }

        // Ignore token refresh/reload paths that can emit login-success events
        // but should not display a second greeting flash.
        return $event->getPreviousToken() === null;
    }

    private function isHtmlRequest(Request $request): bool
    {
        if ($request->isXmlHttpRequest()) {
            return false;
        }

        $requestFormat = $request->getRequestFormat('');
        if ($requestFormat !== '' && $requestFormat !== 'html') {
            return false;
        }

        $preferredFormat = $request->getPreferredFormat('');

        return $preferredFormat === '' || $preferredFormat === 'html';
    }
}
