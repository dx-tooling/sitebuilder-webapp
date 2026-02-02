<?php

declare(strict_types=1);

namespace App\Organization\Domain\SymfonyEventSubscriber;

use App\Account\Facade\SymfonyEvent\AccountCoreCreatedSymfonyEvent;
use App\Organization\Domain\Service\OrganizationDomainServiceInterface;
use App\Organization\Facade\SymfonyEvent\CurrentlyActiveOrganizationChangedSymfonyEvent;
use Exception;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[AsEventListener(event: AccountCoreCreatedSymfonyEvent::class, method: 'handle')]
readonly class AccountCoreCreatedSymfonyEventSubscriber
{
    public function __construct(
        private OrganizationDomainServiceInterface $organizationDomainService,
        private EventDispatcherInterface           $eventDispatcher
    ) {
    }

    /**
     * @throws Exception
     */
    public function handle(
        AccountCoreCreatedSymfonyEvent $event
    ): void {
        $organization = $this
            ->organizationDomainService
            ->createOrganization($event->accountCoreId);

        $this->eventDispatcher->dispatch(
            new CurrentlyActiveOrganizationChangedSymfonyEvent(
                $organization->getId(),
                $event->accountCoreId
            )
        );
    }
}
