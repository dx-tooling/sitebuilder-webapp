<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Domain\Entity;

use App\PhotoBuilder\Domain\Enum\PhotoImageStatus;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;
use Exception;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;

#[ORM\Entity]
#[ORM\Table(name: 'photo_images')]
class PhotoImage
{
    /**
     * @throws Exception
     */
    public function __construct(
        PhotoSession $session,
        int          $position,
    ) {
        $this->session   = $session;
        $this->position  = $position;
        $this->status    = PhotoImageStatus::Pending;
        $this->createdAt = DateAndTimeService::getDateTimeImmutable();

        $session->addImage($this);
    }

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column(
        type: Types::GUID,
        unique: true
    )]
    private ?string $id = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    #[ORM\ManyToOne(
        targetEntity: PhotoSession::class,
        inversedBy: 'images'
    )]
    #[ORM\JoinColumn(
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private readonly PhotoSession $session;

    public function getSession(): PhotoSession
    {
        return $this->session;
    }

    #[ORM\Column(
        type: Types::INTEGER,
        nullable: false
    )]
    private readonly int $position;

    public function getPosition(): int
    {
        return $this->position;
    }

    #[ORM\Column(
        type: Types::TEXT,
        nullable: true
    )]
    private ?string $prompt = null;

    public function getPrompt(): ?string
    {
        return $this->prompt;
    }

    public function setPrompt(?string $prompt): void
    {
        $this->prompt = $prompt;
    }

    #[ORM\Column(
        type: Types::STRING,
        length: 512,
        nullable: true
    )]
    private ?string $suggestedFileName = null;

    public function getSuggestedFileName(): ?string
    {
        return $this->suggestedFileName;
    }

    public function setSuggestedFileName(?string $suggestedFileName): void
    {
        $this->suggestedFileName = $suggestedFileName;
    }

    #[ORM\Column(
        type: Types::STRING,
        length: 32,
        nullable: false,
        enumType: PhotoImageStatus::class
    )]
    private PhotoImageStatus $status;

    public function getStatus(): PhotoImageStatus
    {
        return $this->status;
    }

    public function setStatus(PhotoImageStatus $status): void
    {
        $this->status = $status;
    }

    #[ORM\Column(
        type: Types::STRING,
        length: 1024,
        nullable: true
    )]
    private ?string $storagePath = null;

    public function getStoragePath(): ?string
    {
        return $this->storagePath;
    }

    public function setStoragePath(?string $storagePath): void
    {
        $this->storagePath = $storagePath;
    }

    #[ORM\Column(
        type: Types::TEXT,
        nullable: true
    )]
    private ?string $errorMessage = null;

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): void
    {
        $this->errorMessage = $errorMessage;
    }

    #[ORM\Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: false
    )]
    private readonly DateTimeImmutable $createdAt;

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Whether this image is in a terminal state (completed or failed).
     */
    public function isTerminal(): bool
    {
        return $this->status === PhotoImageStatus::Completed
            || $this->status === PhotoImageStatus::Failed;
    }
}
