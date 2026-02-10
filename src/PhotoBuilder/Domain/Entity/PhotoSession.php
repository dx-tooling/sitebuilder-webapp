<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Domain\Entity;

use App\PhotoBuilder\Domain\Enum\PhotoImageStatus;
use App\PhotoBuilder\Domain\Enum\PhotoSessionStatus;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;
use Exception;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;

#[ORM\Entity]
#[ORM\Table(name: 'photo_sessions')]
class PhotoSession
{
    /**
     * @throws Exception
     */
    public function __construct(
        string $workspaceId,
        string $conversationId,
        string $pagePath,
        string $userPrompt,
    ) {
        $this->workspaceId    = $workspaceId;
        $this->conversationId = $conversationId;
        $this->pagePath       = $pagePath;
        $this->userPrompt     = $userPrompt;
        $this->status         = PhotoSessionStatus::GeneratingPrompts;
        $this->createdAt      = DateAndTimeService::getDateTimeImmutable();
        $this->images         = new ArrayCollection();
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

    #[ORM\Column(
        type: Types::GUID,
        nullable: false
    )]
    private readonly string $workspaceId;

    public function getWorkspaceId(): string
    {
        return $this->workspaceId;
    }

    #[ORM\Column(
        type: Types::GUID,
        nullable: false
    )]
    private readonly string $conversationId;

    public function getConversationId(): string
    {
        return $this->conversationId;
    }

    #[ORM\Column(
        type: Types::STRING,
        length: 512,
        nullable: false
    )]
    private readonly string $pagePath;

    public function getPagePath(): string
    {
        return $this->pagePath;
    }

    #[ORM\Column(
        type: Types::TEXT,
        nullable: false
    )]
    private string $userPrompt;

    public function getUserPrompt(): string
    {
        return $this->userPrompt;
    }

    public function setUserPrompt(string $userPrompt): void
    {
        $this->userPrompt = $userPrompt;
    }

    #[ORM\Column(
        type: Types::STRING,
        length: 32,
        nullable: false,
        enumType: PhotoSessionStatus::class
    )]
    private PhotoSessionStatus $status;

    public function getStatus(): PhotoSessionStatus
    {
        return $this->status;
    }

    public function setStatus(PhotoSessionStatus $status): void
    {
        $this->status = $status;
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
     * @var Collection<int, PhotoImage>
     */
    #[ORM\OneToMany(
        targetEntity: PhotoImage::class,
        mappedBy: 'session',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $images;

    /**
     * @return Collection<int, PhotoImage>
     */
    public function getImages(): Collection
    {
        return $this->images;
    }

    public function addImage(PhotoImage $image): void
    {
        if (!$this->images->contains($image)) {
            $this->images->add($image);
        }
    }

    /**
     * Check whether all images in the session have reached a terminal state.
     */
    public function areAllImagesTerminal(): bool
    {
        if ($this->images->isEmpty()) {
            return false;
        }

        foreach ($this->images as $image) {
            if (!$image->isTerminal()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check whether all images completed successfully.
     */
    public function areAllImagesCompleted(): bool
    {
        if ($this->images->isEmpty()) {
            return false;
        }

        foreach ($this->images as $image) {
            if ($image->getStatus() !== PhotoImageStatus::Completed) {
                return false;
            }
        }

        return true;
    }
}
