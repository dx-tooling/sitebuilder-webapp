<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Domain\Entity;

use App\ChatBasedContentEditor\Domain\Enum\EditSessionStatus;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;
use Exception;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;

#[ORM\Entity]
#[ORM\Table(name: 'edit_sessions')]
class EditSession
{
    /**
     * Creates an EditSession within a conversation.
     *
     * @throws Exception
     */
    public static function createWithConversation(
        Conversation $conversation,
        string       $instruction
    ): self {
        $session               = new self($conversation->getWorkspacePath(), $instruction);
        $session->conversation = $conversation;
        $conversation->addEditSession($session);

        return $session;
    }

    /**
     * @deprecated Use createWithConversation() for new sessions
     *
     * @throws Exception
     */
    public function __construct(
        string $workspacePath,
        string $instruction
    ) {
        $this->workspacePath = $workspacePath;
        $this->instruction   = $instruction;
        $this->status        = EditSessionStatus::Pending;
        $this->createdAt     = DateAndTimeService::getDateTimeImmutable();
        $this->chunks        = new ArrayCollection();
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
        targetEntity: Conversation::class,
        inversedBy: 'editSessions'
    )]
    #[ORM\JoinColumn(
        nullable: true,
        onDelete: 'CASCADE'
    )]
    private ?Conversation $conversation = null;

    public function getConversation(): ?Conversation
    {
        return $this->conversation;
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
        type: Types::TEXT,
        nullable: false
    )]
    private readonly string $instruction;

    public function getInstruction(): string
    {
        return $this->instruction;
    }

    #[ORM\Column(
        type: Types::STRING,
        length: 32,
        nullable: false,
        enumType: EditSessionStatus::class
    )]
    private EditSessionStatus $status;

    public function getStatus(): EditSessionStatus
    {
        return $this->status;
    }

    public function setStatus(EditSessionStatus $status): void
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
     * @var Collection<int, EditSessionChunk>
     */
    #[ORM\OneToMany(
        targetEntity: EditSessionChunk::class,
        mappedBy: 'session',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $chunks;

    /**
     * @return Collection<int, EditSessionChunk>
     */
    public function getChunks(): Collection
    {
        return $this->chunks;
    }

    public function addChunk(EditSessionChunk $chunk): void
    {
        if (!$this->chunks->contains($chunk)) {
            $this->chunks->add($chunk);
        }
    }
}
