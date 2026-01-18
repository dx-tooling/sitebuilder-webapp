<?php

declare(strict_types=1);

namespace App\Common\Presentation\Service;

use App\Common\Presentation\Entity\AppNotification;
use App\Common\Presentation\Enum\AppNotificationType;
use Doctrine\ORM\EntityManagerInterface;
use EnterpriseToolingForSymfony\SharedBundle\LittleHelpers\Enum\Order;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\RouterInterface;
use Throwable;

readonly class AppNotificationsPresentationService
{
    public function __construct(
        private LoggerInterface        $logger,
        private EntityManagerInterface $entityManager,
        private RouterInterface        $router
    ) {
    }

    /**
     * @return list<AppNotification>
     */
    public function getLatestAppNotifications(): array
    {
        return $this
            ->entityManager
            ->getRepository(AppNotification::class)
            ->findBy(
                [],
                ['createdAt' => Order::Descending->value],
                5
            );
    }

    public function getNumberOfUnreadAppNotifications(): int
    {
        return $this
            ->entityManager
            ->getRepository(AppNotification::class)
            ->count(['isRead' => false]);
    }

    public function markAllAppNotificationsAsRead(): void
    {
        $appNotifications = $this
            ->entityManager
            ->getRepository(AppNotification::class)
            ->findBy(['isRead' => false]);

        foreach ($appNotifications as $appNotification) {
            $appNotification->setIsRead(true);
        }

        $this
            ->entityManager
            ->flush();
    }

    public function deleteOldAppNotifications(): void
    {
        $sql = <<<SQL
            DELETE FROM {$this->entityManager->getClassMetadata(AppNotification::class)->getTableName()}
            WHERE created_at < NOW() - INTERVAL 1 DAY
        SQL;

        try {
            $this
                ->entityManager
                ->getConnection()
                ->executeStatement($sql);
        } catch (Throwable $t) {
            $this
                ->logger
                ->error(
                    "Failed to delete old app notifications: {$t->getMessage()}",
                    ['exception' => $t]
                );
        }
    }

    public function createAppNotification(
        AppNotificationType $type,
        string              $message
    ): AppNotification {
        $appNotification = match ($type) {
            AppNotificationType::GenericText => new AppNotification(
                $type,
                $message,
                $this->router->generate(
                    'content.presentation.homepage'
                )
            )
        };

        $this
            ->entityManager
            ->persist($appNotification);

        $this
            ->entityManager
            ->flush();

        $this->deleteOldAppNotifications();

        return $appNotification;
    }
}
