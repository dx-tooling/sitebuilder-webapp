<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;

readonly class MailService implements MailServiceInterface
{
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire(param: 'app.mail.default_sender_address')]
        private string          $defaultSenderAddress
    ) {
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function send(
        Message $email,
        bool    $autoresponserProtection = true
    ): void {
        if ($autoresponserProtection) {
            // this non-standard header tells compliant autoresponders ("email holiday mode")
            // to not reply to this message because it's an automated email
            $email->setHeaders(
                $email
                    ->getHeaders()
                    ->addTextHeader(
                        'X-Auto-Response-Suppress',
                        'OOF, DR, RN, NRN, AutoReply'
                    )
            );
        }

        $this->mailer->send($email);
    }

    public function getDefaultSenderAddress(): Address
    {
        return new Address(
            $this->defaultSenderAddress,
            'SiteBuilder'
        );
    }
}
