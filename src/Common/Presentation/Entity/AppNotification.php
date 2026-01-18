<?php

declare(strict_types=1);

namespace App\Common\Presentation\Entity;

use App\Common\Presentation\Enum\AppNotificationType;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;

#[ORM\Entity]
#[ORM\Table(name: 'app_notifications')]
#[ORM\Index(
    name: 'created_at_is_read_idx',
    fields: ['createdAt', 'isRead']
)]
class AppNotification
{
    public function __construct(
        AppNotificationType $type,
        string              $message,
        string              $url
    ) {
        $this->createdAt = DateAndTimeService::getDateTimeImmutable();
        $this->type      = $type;
        $this->message   = $message;
        $this->url       = $url;
    }

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column(
        type  : Types::GUID,
        unique: true
    )]
    private ?string $id = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    #[ORM\Column(
        type    : Types::DATETIME_IMMUTABLE,
        nullable: false
    )]
    private readonly DateTimeImmutable $createdAt;

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[ORM\Column(
        type  : Types::STRING,
        length: 1024
    )]
    private readonly string $message;

    public function getMessage(): string
    {
        return $this->message;
    }

    #[ORM\Column(
        type  : Types::STRING,
        length: 1024
    )]
    private readonly string $url;

    public function getUrl(): string
    {
        return $this->url;
    }

    #[ORM\Column(
        type    : Types::SMALLINT,
        nullable: false,
        enumType: AppNotificationType::class,
        options : ['unsigned' => true]
    )]
    private readonly AppNotificationType $type;

    public function getType(): AppNotificationType
    {
        return $this->type;
    }

    #[ORM\Column(
        type: Types::BOOLEAN
    )]
    private bool $isRead = false;

    public function getIsRead(): bool
    {
        return $this->isRead;
    }

    public function setIsRead(bool $isRead): void
    {
        $this->isRead = $isRead;
    }
}
