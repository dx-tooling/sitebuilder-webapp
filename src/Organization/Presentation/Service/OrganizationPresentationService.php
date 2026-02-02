<?php

declare(strict_types=1);

namespace App\Organization\Presentation\Service;

use App\Account\Facade\AccountFacadeInterface;
use App\Organization\Domain\Entity\Invitation;
use App\Shared\Presentation\Service\MailServiceInterface;
use Exception;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

readonly class OrganizationPresentationService implements OrganizationPresentationServiceInterface
{
    public function __construct(
        private MailServiceInterface   $mailService,
        private TranslatorInterface    $translator,
        private RouterInterface        $router,
        private AccountFacadeInterface $accountFacade
    ) {
    }

    /**
     * @throws TransportExceptionInterface
     * @throws Exception
     */
    public function sendInvitationMail(
        Invitation $invitation
    ): void {
        $owningUserId   = $invitation->getOrganization()->getOwningUsersId();
        $owningUserName = $this->accountFacade->getAccountCoreEmailById($owningUserId) ?? $this->translator->trans('fallback.someone', [], 'organization');

        $context = [
            'acceptUrl' => $this->router->generate(
                'organization.presentation.accept_invitation',
                ['invitationId' => $invitation->getId()],
                UrlGeneratorInterface::ABSOLUTE_URL
            ),
            'owningUserName' => $owningUserName
        ];

        $this->mailService->send(
            new TemplatedEmail()
                ->from($this->mailService->getDefaultSenderAddress())
                ->to($invitation->getEmail())
                ->subject(
                    $this->translator->trans(
                        'invitation.email.subject',
                        ['owningUserName' => $owningUserName],
                        'organization'
                    )
                )
                ->htmlTemplate(
                    '@organization.presentation/invitation_email.html.twig'
                )
                ->context($context)
        );
    }
}
