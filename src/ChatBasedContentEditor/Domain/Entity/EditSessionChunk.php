<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Domain\Entity;

use App\ChatBasedContentEditor\Domain\Enum\EditSessionChunkType;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;
use Exception;
use JsonException;

use const JSON_THROW_ON_ERROR;

#[ORM\Entity]
#[ORM\Table(name: 'edit_session_chunks')]
#[ORM\Index(columns: ['session_id', 'id'], name: 'idx_session_chunk_polling')]
class EditSessionChunk
{
    /**
     * @throws Exception
     * @throws JsonException
     */
    public static function createTextChunk(EditSession $session, string $content): self
    {
        return new self(
            $session,
            EditSessionChunkType::Text,
            json_encode(['content' => $content], JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @throws Exception
     * @throws JsonException
     */
    public static function createEventChunk(EditSession $session, string $eventJson): self
    {
        return new self(
            $session,
            EditSessionChunkType::Event,
            $eventJson
        );
    }

    /**
     * @throws Exception
     * @throws JsonException
     */
    public static function createDoneChunk(EditSession $session, bool $success, ?string $errorMessage = null): self
    {
        return new self(
            $session,
            EditSessionChunkType::Done,
            json_encode(['success' => $success, 'errorMessage' => $errorMessage], JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @throws Exception
     */
    private function __construct(
        EditSession          $session,
        EditSessionChunkType $chunkType,
        string               $payloadJson
    ) {
        $this->session     = $session;
        $this->chunkType   = $chunkType;
        $this->payloadJson = $payloadJson;
        $this->createdAt   = DateAndTimeService::getDateTimeImmutable();

        $session->addChunk($this);
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
        targetEntity: EditSession::class,
        inversedBy: 'chunks'
    )]
    #[ORM\JoinColumn(
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private readonly EditSession $session;

    public function getSession(): EditSession
    {
        return $this->session;
    }

    #[ORM\Column(
        type: Types::STRING,
        length: 32,
        nullable: false,
        enumType: EditSessionChunkType::class
    )]
    private readonly EditSessionChunkType $chunkType;

    public function getChunkType(): EditSessionChunkType
    {
        return $this->chunkType;
    }

    #[ORM\Column(
        type: Types::TEXT,
        nullable: false
    )]
    private readonly string $payloadJson;

    public function getPayloadJson(): string
    {
        return $this->payloadJson;
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
