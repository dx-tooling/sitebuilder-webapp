<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Domain\Entity;

use App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;
use Exception;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;

#[ORM\Entity]
#[ORM\Table(name: 'workspaces')]
#[ORM\Index(columns: ['project_id'], name: 'idx_workspace_project')]
#[ORM\Index(columns: ['status'], name: 'idx_workspace_status')]
class Workspace
{
    /**
     * @throws Exception
     */
    public function __construct(
        string $projectId
    ) {
        $this->projectId = $projectId;
        $this->status    = WorkspaceStatus::AVAILABLE_FOR_SETUP;
        $this->createdAt = DateAndTimeService::getDateTimeImmutable();
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
    private readonly string $projectId;

    public function getProjectId(): string
    {
        return $this->projectId;
    }

    #[ORM\Column(
        type: Types::INTEGER,
        nullable: false,
        enumType: WorkspaceStatus::class
    )]
    private WorkspaceStatus $status;

    public function getStatus(): WorkspaceStatus
    {
        return $this->status;
    }

    public function setStatus(WorkspaceStatus $status): void
    {
        $this->status = $status;
    }

    #[ORM\Column(
        type: Types::STRING,
        length: 255,
        nullable: true
    )]
    private ?string $branchName = null;

    public function getBranchName(): ?string
    {
        return $this->branchName;
    }

    public function setBranchName(?string $branchName): void
    {
        $this->branchName = $branchName;
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
