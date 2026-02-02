<?php

declare(strict_types=1);

namespace App\Account\Presentation\Controller;

use App\Account\Domain\Service\AccountDomainService;
use App\Account\Infrastructure\Security\SecurityUserProvider;
use App\Common\Domain\Security\SecurityUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

final class AccountController extends AbstractController
{
    public function __construct(
        private readonly AccountDomainService $accountService,
        private readonly TranslatorInterface  $translator,
        private readonly Security             $security,
        private readonly SecurityUserProvider $securityUserProvider
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

        $formData = [
            'email'    => '',
            'password' => '',
        ];

        if ($request->isMethod(Request::METHOD_POST)) {
            $email           = $request->request->get('email');
            $password        = $request->request->get('password');
            $passwordConfirm = $request->request->get('password_confirm');

            $formData['email']    = (string) $email;
            $formData['password'] = (string) $password;

            if (!$email || !$password) {
                $this->addFlash('error', $this->translator->trans('flash.error.provide_email_password'));

                return $this->render('@account.presentation/sign_up.html.twig', $formData);
            }

            if ($password !== $passwordConfirm) {
                $this->addFlash('error', $this->translator->trans('flash.error.passwords_mismatch'));

                return $this->render('@account.presentation/sign_up.html.twig', $formData);
            }

            try {
                $this->accountService->register((string) $email, (string) $password);
                // Load user as SecurityUser via provider to store correct class in session
                $securityUser = $this->securityUserProvider->loadUserByIdentifier((string) $email);
                $this->security->login($securityUser, 'form_login', 'main');

                return $this->redirectToRoute('account.presentation.dashboard');
            } catch (Throwable $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->render('@account.presentation/sign_up.html.twig', $formData);
            }
        }

        return $this->render('@account.presentation/sign_up.html.twig', $formData);
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
        /** @var SecurityUser|null $securityUser */
        $securityUser = $this->getUser();

        if ($securityUser !== null && $securityUser->getMustSetPassword()) {
            return $this->redirectToRoute('account.presentation.set_password');
        }

        return $this->render('@account.presentation/account_dashboard.twig');
    }

    #[Route(
        path: '/account/set-password',
        name: 'account.presentation.set_password',
        methods: [Request::METHOD_GET, Request::METHOD_POST]
    )]
    public function setPasswordAction(Request $request): Response
    {
        /** @var SecurityUser|null $securityUser */
        $securityUser = $this->getUser();

        if ($securityUser === null) {
            return $this->redirectToRoute('account.presentation.sign_in');
        }

        // If user doesn't need to set password, redirect to dashboard
        if (!$securityUser->getMustSetPassword()) {
            return $this->redirectToRoute('account.presentation.dashboard');
        }

        if ($request->isMethod(Request::METHOD_POST)) {
            $password        = $request->request->get('password');
            $passwordConfirm = $request->request->get('password_confirm');

            if (!$password) {
                $this->addFlash('error', $this->translator->trans('flash.error.password_required'));

                return $this->render('@account.presentation/set_password.html.twig');
            }

            if ($password !== $passwordConfirm) {
                $this->addFlash('error', $this->translator->trans('flash.error.passwords_mismatch'));

                return $this->render('@account.presentation/set_password.html.twig');
            }

            // Update password and clear the must-set-password flag via domain service
            $userId = $securityUser->getId();
            $this->accountService->setMustSetPasswordById($userId, false);
            $this->accountService->updatePasswordById($userId, (string) $password);

            $this->addFlash('success', $this->translator->trans('flash.success.password_set'));

            return $this->redirectToRoute('account.presentation.dashboard');
        }

        return $this->render('@account.presentation/set_password.html.twig');
    }
}
