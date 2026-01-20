<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Domain\Entity;

use App\ChatBasedContentEditor\Domain\Enum\ConversationMessageRole;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;
use Exception;

#[ORM\Entity]
#[ORM\Table(name: 'conversation_messages')]
#[ORM\Index(columns: ['conversation_id', 'sequence'], name: 'idx_conversation_message_sequence')]
class ConversationMessage
{
    /**
     * @throws Exception
     */
    public function __construct(
        Conversation            $conversation,
        ConversationMessageRole $role,
        string                  $contentJson
    ) {
        $this->conversation = $conversation;
        $this->role         = $role;
        $this->contentJson  = $contentJson;
        $this->sequence     = $conversation->getNextMessageSequence();
        $this->createdAt    = DateAndTimeService::getDateTimeImmutable();

        $conversation->addMessage($this);
    }

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(
        type: Types::INTEGER
    )]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    #[ORM\ManyToOne(
        targetEntity: Conversation::class,
        inversedBy: 'messages'
    )]
    #[ORM\JoinColumn(
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private readonly Conversation $conversation;

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    #[ORM\Column(
        type: Types::STRING,
        length: 32,
        nullable: false,
        enumType: ConversationMessageRole::class
    )]
    private readonly ConversationMessageRole $role;

    public function getRole(): ConversationMessageRole
    {
        return $this->role;
    }

    #[ORM\Column(
        type: Types::TEXT,
        nullable: false
    )]
    private readonly string $contentJson;

    public function getContentJson(): string
    {
        return $this->contentJson;
    }

    #[ORM\Column(
        type: Types::INTEGER,
        nullable: false
    )]
    private readonly int $sequence;

    public function getSequence(): int
    {
        return $this->sequence;
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
}
