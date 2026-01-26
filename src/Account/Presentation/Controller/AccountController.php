<?php

declare(strict_types=1);

namespace App\Account\Presentation\Controller;

use App\Account\Domain\Service\AccountDomainService;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AccountController extends AbstractController
{
    public function __construct(
        private readonly AccountDomainService $accountService,
        private readonly TranslatorInterface  $translator
    ) {
    }

    #[Route(
        path: '/account/sign-in',
        name: 'account.presentation.sign_in',
        methods: [Request::METHOD_GET, Request::METHOD_POST]
    )]
    public function signInAction(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('account.presentation.dashboard');
        }

        $error        = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('@account.presentation/sign_in.html.twig', [
            'last_username' => $lastUsername,
            'error'         => $error,
        ]);
    }

    #[Route(
        path: '/account/sign-up',
        name: 'account.presentation.sign_up',
        methods: [Request::METHOD_GET, Request::METHOD_POST]
    )]
    public function signUpAction(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('account.presentation.dashboard');
        }

        if ($request->isMethod(Request::METHOD_POST)) {
            $email    = $request->request->get('email');
            $password = $request->request->get('password');

            if (!$email || !$password) {
                $this->addFlash('error', $this->translator->trans('flash.error.provide_email_password'));

                return $this->render('@account.presentation/sign_up.html.twig');
            }

            try {
                $this->accountService->register((string) $email, (string) $password);
                $this->addFlash('success', $this->translator->trans('flash.success.registration_successful'));

                return $this->redirectToRoute('account.presentation.sign_in');
            } catch (Exception $e) {
                $this->addFlash('error', $this->translator->trans('flash.error.registration_failed', ['%error%' => $e->getMessage()]));
            }
        }

        return $this->render('@account.presentation/sign_up.html.twig');
    }

    #[Route(
        path: '/account/sign-out',
        name: 'account.presentation.sign_out',
        methods: [Request::METHOD_POST]
    )]
    public function signOutAction(): void
    {
        // This method can be empty - it will be intercepted by the logout key on your firewall
    }

    #[Route(
        path: '/account/dashboard',
        name: 'account.presentation.dashboard',
        methods: [Request::METHOD_GET]
    )]
    public function dashboardAction(): Response
    {
        return $this->render('@account.presentation/account_dashboard.twig');
    }
}
