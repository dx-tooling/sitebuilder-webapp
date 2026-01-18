<?php

declare(strict_types=1);

namespace App\Common\Presentation\Component;

use App\Common\Presentation\Entity\AppNotification;
use App\Common\Presentation\Service\AppNotificationsPresentationService;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(
    name    : 'common|presentation|app_notifications_widget',
    template: '@common.presentation/app_notifications_widget.component.html.twig'
)]
class AppNotificationsWidgetComponent extends AbstractController
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    public function __construct(
        private readonly AppNotificationsPresentationService $presentationService
    ) {
    }

    #[LiveProp]
    public bool $widgetIsOpen = false;

    /**
     * @return list<AppNotification>
     *
     * @throws Exception
     */
    #[LiveAction]
    public function getLatestAppNotifications(): array
    {
        return $this
            ->presentationService
            ->getLatestAppNotifications();
    }

    /**
     * @throws Exception
     */
    #[LiveAction]
    public function getNumberOfUnreadAppNotifications(): int
    {
        return $this
            ->presentationService
            ->getNumberOfUnreadAppNotifications();
    }

    #[LiveAction]
    public function openWidget(): void
    {
        $this->widgetIsOpen = true;
        $this->presentationService->markAllAppNotificationsAsRead();
    }

    #[LiveAction]
    public function closeWidget(): void
    {
        $this->widgetIsOpen = false;
    }
}
