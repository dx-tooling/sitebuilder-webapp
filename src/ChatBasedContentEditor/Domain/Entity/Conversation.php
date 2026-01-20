<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Domain\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;
use Exception;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;

#[ORM\Entity]
#[ORM\Table(name: 'conversations')]
class Conversation
{
    /**
     * @throws Exception
     */
    public function __construct(string $workspacePath)
    {
        $this->workspacePath = $workspacePath;
        $this->createdAt     = DateAndTimeService::getDateTimeImmutable();
        $this->editSessions  = new ArrayCollection();
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
        type: Types::STRING,
        length: 4096,
        nullable: false
    )]
    private readonly string $workspacePath;

    public function getWorkspacePath(): string
    {
        return $this->workspacePath;
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
     * @var Collection<int, EditSession>
     */
    #[ORM\OneToMany(
        targetEntity: EditSession::class,
        mappedBy: 'conversation',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $editSessions;

    /**
     * @return Collection<int, EditSession>
     */
    public function getEditSessions(): Collection
    {
        return $this->editSessions;
    }
}
