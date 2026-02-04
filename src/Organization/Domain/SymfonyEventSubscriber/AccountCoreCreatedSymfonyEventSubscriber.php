<?php

declare(strict_types=1);

namespace App\Organization\Domain\SymfonyEventSubscriber;

use App\Account\Facade\SymfonyEvent\AccountCoreCreatedSymfonyEvent;
use App\Organization\Domain\Service\OrganizationDomainServiceInterface;
use App\Organization\Facade\SymfonyEvent\CurrentlyActiveOrganizationChangedSymfonyEvent;
use App\Prefab\Facade\PrefabFacadeInterface;
use App\ProjectMgmt\Facade\ProjectMgmtFacadeInterface;
use App\WorkspaceMgmt\Facade\WorkspaceMgmtFacadeInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Throwable;

#[AsEventListener(event: AccountCoreCreatedSymfonyEvent::class, method: 'handle')]
readonly class AccountCoreCreatedSymfonyEventSubscriber
{
    public function __construct(
        private OrganizationDomainServiceInterface $organizationDomainService,
        private EventDispatcherInterface           $eventDispatcher,
        private PrefabFacadeInterface              $prefabFacade,
        private ProjectMgmtFacadeInterface         $projectMgmtFacade,
        private WorkspaceMgmtFacadeInterface       $workspaceMgmtFacade,
        private LoggerInterface                    $logger
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

        $prefabs = $this->prefabFacade->loadPrefabs();
        foreach ($prefabs as $prefab) {
            try {
                $projectId = $this->projectMgmtFacade->createProjectFromPrefab($organization->getId(), $prefab);
                $this->workspaceMgmtFacade->dispatchSetupIfNeeded($projectId);
            } catch (Throwable $e) {
                $this->logger->warning('Prefab project creation failed', [
                    'organization_id' => $organization->getId(),
                    'prefab_name'     => $prefab->name,
                    'error'           => $e->getMessage(),
                ]);
            }
        }
    }
}
