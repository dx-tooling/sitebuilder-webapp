<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Domain\Entity;

use App\ChatBasedContentEditor\Domain\Enum\ContentEditorBackend;
use App\ChatBasedContentEditor\Domain\Enum\ConversationStatus;
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
#[ORM\Index(columns: ['workspace_id'], name: 'idx_conversation_workspace')]
#[ORM\Index(columns: ['user_id'], name: 'idx_conversation_user')]
#[ORM\Index(columns: ['workspace_id', 'user_id', 'status'], name: 'idx_conversation_workspace_user_status')]
class Conversation
{
    /**
     * @throws Exception
     */
    public function __construct(
        string               $workspaceId,
        string               $userId,
        string               $workspacePath,
        ContentEditorBackend $contentEditorBackend = ContentEditorBackend::Llm
    ) {
        $this->workspaceId          = $workspaceId;
        $this->userId               = $userId;
        $this->workspacePath        = $workspacePath;
        $this->status               = ConversationStatus::ONGOING;
        $this->contentEditorBackend = $contentEditorBackend;
        $this->createdAt            = DateAndTimeService::getDateTimeImmutable();
        $this->editSessions         = new ArrayCollection();
        $this->messages             = new ArrayCollection();
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
    private readonly string $userId;

    public function getUserId(): string
    {
        return $this->userId;
    }

    #[ORM\Column(
        type: Types::STRING,
        length: 32,
        nullable: false,
        enumType: ConversationStatus::class
    )]
    private ConversationStatus $status;

    public function getStatus(): ConversationStatus
    {
        return $this->status;
    }

    public function setStatus(ConversationStatus $status): void
    {
        $this->status = $status;
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
        type: Types::STRING,
        length: 32,
        nullable: false,
        enumType: ContentEditorBackend::class,
        options: ['default' => ContentEditorBackend::Llm->value]
    )]
    private ContentEditorBackend $contentEditorBackend = ContentEditorBackend::Llm;

    public function getContentEditorBackend(): ContentEditorBackend
    {
        return $this->contentEditorBackend;
    }

    public function setContentEditorBackend(ContentEditorBackend $contentEditorBackend): void
    {
        $this->contentEditorBackend = $contentEditorBackend;
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

    #[ORM\Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true
    )]
    private ?DateTimeImmutable $lastActivityAt = null;

    public function getLastActivityAt(): ?DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    /**
     * Update the last activity timestamp to current time.
     *
     * @throws Exception
     */
    public function updateLastActivity(): void
    {
        $this->lastActivityAt = DateAndTimeService::getDateTimeImmutable();
    }

    #[ORM\Column(
        type: Types::STRING,
        length: 64,
        nullable: true
    )]
    private ?string $cursorAgentSessionId = null;

    public function getCursorAgentSessionId(): ?string
    {
        return $this->cursorAgentSessionId;
    }

    public function setCursorAgentSessionId(?string $cursorAgentSessionId): void
    {
        $this->cursorAgentSessionId = $cursorAgentSessionId;
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

    /**
     * @var Collection<int, ConversationMessage>
     */
    #[ORM\OneToMany(
        targetEntity: ConversationMessage::class,
        mappedBy: 'conversation',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy(['sequence' => 'ASC'])]
    private Collection $messages;

    /**
     * @return Collection<int, ConversationMessage>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(ConversationMessage $message): void
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
        }
    }

    public function getNextMessageSequence(): int
    {
        $lastMessage = $this->messages->last();
        if ($lastMessage === false) {
            return 1;
        }

        return $lastMessage->getSequence() + 1;
    }
}
