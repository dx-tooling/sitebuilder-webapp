<?php

declare(strict_types=1);

namespace App\Account\Domain\SymfonyEventSubscriber;

use App\Account\Domain\Entity\AccountCore;
use App\Organization\Facade\SymfonyEvent\CurrentlyActiveOrganizationChangedSymfonyEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: CurrentlyActiveOrganizationChangedSymfonyEvent::class, method: 'handle')]
readonly class CurrentlyActiveOrganizationChangedSymfonyEventSubscriber
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    public function handle(
        CurrentlyActiveOrganizationChangedSymfonyEvent $event
    ): void {
        $account = $this->entityManager->find(AccountCore::class, $event->affectedUserId);

        if ($account === null) {
            return;
        }

        $account->setCurrentlyActiveOrganizationId($event->organizationId);
        $this->entityManager->persist($account);
        $this->entityManager->flush();
    }
}
