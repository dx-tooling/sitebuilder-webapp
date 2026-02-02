<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Service;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;

interface MailServiceInterface
{
    public function send(
        Message $email,
        bool    $autoresponserProtection = true
    ): void;

    public function getDefaultSenderAddress(): Address;
}
