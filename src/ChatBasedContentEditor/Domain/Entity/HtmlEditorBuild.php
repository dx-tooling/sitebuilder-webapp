<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Domain\Entity;

use App\ChatBasedContentEditor\Domain\Enum\HtmlEditorBuildStatus;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;
use Exception;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;

#[ORM\Entity]
#[ORM\Table(name: 'html_editor_builds')]
class HtmlEditorBuild
{
    /**
     * @throws Exception
     */
    public function __construct(
        string $workspaceId,
        string $sourcePath,
        string $userEmail
    ) {
        $this->workspaceId = $workspaceId;
        $this->sourcePath  = $sourcePath;
        $this->userEmail   = $userEmail;
        $this->status      = HtmlEditorBuildStatus::Pending;
        $this->createdAt   = DateAndTimeService::getDateTimeImmutable();
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
        type: Types::STRING,
        length: 512,
        nullable: false
    )]
    private readonly string $sourcePath;

    public function getSourcePath(): string
    {
        return $this->sourcePath;
    }

    #[ORM\Column(
        type: Types::STRING,
        length: 255,
        nullable: false
    )]
    private readonly string $userEmail;

    public function getUserEmail(): string
    {
        return $this->userEmail;
    }

    #[ORM\Column(
        type: Types::STRING,
        length: 32,
        nullable: false,
        enumType: HtmlEditorBuildStatus::class
    )]
    private HtmlEditorBuildStatus $status;

    public function getStatus(): HtmlEditorBuildStatus
    {
        return $this->status;
    }

    public function setStatus(HtmlEditorBuildStatus $status): void
    {
        $this->status = $status;
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
}
