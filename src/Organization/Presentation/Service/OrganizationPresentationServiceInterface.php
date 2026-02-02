<?php

declare(strict_types=1);

namespace App\Organization\Presentation\Service;

use App\Organization\Domain\Entity\Invitation;

interface OrganizationPresentationServiceInterface
{
    public function sendInvitationMail(Invitation $invitation): void;
}
