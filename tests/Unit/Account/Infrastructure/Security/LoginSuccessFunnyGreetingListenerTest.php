<?php

declare(strict_types=1);

namespace App\Tests\Unit\Account\Infrastructure\Security;

use App\Account\Infrastructure\Security\FunnyGreetingProvider;
use App\Account\Infrastructure\Security\LoginSuccessFunnyGreetingListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class LoginSuccessFunnyGreetingListenerTest extends TestCase
{
    public function testHandleAddsAuthGreetingFlashForMainFirewall(): void
    {
        $provider = new FunnyGreetingProvider();
        $listener = new LoginSuccessFunnyGreetingListener($provider);
        $request  = new Request();
        $session  = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $event = $this->createEvent($request);
        $listener->handle($event);

        $flashMessages = $session->getFlashBag()->get(FunnyGreetingProvider::FLASH_TYPE);
        self::assertCount(1, $flashMessages);
        self::assertContains($flashMessages[0], $provider->getAvailableGreetingKeys());
    }

    public function testHandleSkipsWhenPreviousTokenExists(): void
    {
        $provider = new FunnyGreetingProvider();
        $listener = new LoginSuccessFunnyGreetingListener($provider);
        $request  = new Request();
        $session  = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $previousToken = $this->createMock(TokenInterface::class);
        $event         = $this->createEvent($request, 'main', $previousToken);
        $listener->handle($event);

        self::assertSame([], $session->getFlashBag()->get(FunnyGreetingProvider::FLASH_TYPE));
    }

    public function testHandleSkipsForNonMainFirewall(): void
    {
        $provider = new FunnyGreetingProvider();
        $listener = new LoginSuccessFunnyGreetingListener($provider);
        $request  = new Request();
        $session  = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $event = $this->createEvent($request, 'admin');
        $listener->handle($event);

        self::assertSame([], $session->getFlashBag()->get(FunnyGreetingProvider::FLASH_TYPE));
    }

    public function testHandleSkipsForNonRedirectResponse(): void
    {
        $provider = new FunnyGreetingProvider();
        $listener = new LoginSuccessFunnyGreetingListener($provider);
        $request  = new Request();
        $session  = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $event = $this->createEvent($request, 'main', null, new JsonResponse(['status' => 'ok']));
        $listener->handle($event);

        self::assertSame([], $session->getFlashBag()->get(FunnyGreetingProvider::FLASH_TYPE));
    }

    public function testHandleSkipsWhenRequestHasNoSession(): void
    {
        $provider = new FunnyGreetingProvider();
        $listener = new LoginSuccessFunnyGreetingListener($provider);
        $request  = new Request();

        $event = $this->createEvent($request);
        $listener->handle($event);

        self::assertFalse($request->hasSession());
    }

    public function testHandleSkipsForJsonRequests(): void
    {
        $provider = new FunnyGreetingProvider();
        $listener = new LoginSuccessFunnyGreetingListener($provider);
        $request  = new Request([], [], [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $session  = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $event = $this->createEvent($request);
        $listener->handle($event);

        self::assertSame([], $session->getFlashBag()->get(FunnyGreetingProvider::FLASH_TYPE));
    }

    public function testHandleSkipsForXmlHttpRequests(): void
    {
        $provider = new FunnyGreetingProvider();
        $listener = new LoginSuccessFunnyGreetingListener($provider);
        $request  = new Request([], [], [], [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $session  = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $event = $this->createEvent($request);
        $listener->handle($event);

        self::assertSame([], $session->getFlashBag()->get(FunnyGreetingProvider::FLASH_TYPE));
    }

    private function createEvent(
        Request         $request,
        string          $firewallName = 'main',
        ?TokenInterface $previousToken = null,
        ?Response       $response = null,
    ): LoginSuccessEvent {
        $authenticator     = $this->createMock(AuthenticatorInterface::class);
        $passport          = new SelfValidatingPassport(new UserBadge('test@example.com'));
        $authenticatedUser = $this->createMock(TokenInterface::class);

        return new LoginSuccessEvent(
            $authenticator,
            $passport,
            $authenticatedUser,
            $request,
            $response,
            $firewallName,
            $previousToken
        );
    }
}
