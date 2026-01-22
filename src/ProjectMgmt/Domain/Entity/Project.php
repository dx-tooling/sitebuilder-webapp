<?php

declare(strict_types=1);

namespace App\ProjectMgmt\Domain\Entity;

use App\ProjectMgmt\Facade\Enum\ProjectType;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;
use Exception;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;

#[ORM\Entity]
#[ORM\Table(name: 'projects')]
class Project
{
    /**
     * @throws Exception
     */
    public function __construct(
        string      $name,
        string      $gitUrl,
        string      $githubToken,
        ProjectType $projectType = ProjectType::DEFAULT
    ) {
        $this->name        = $name;
        $this->gitUrl      = $gitUrl;
        $this->githubToken = $githubToken;
        $this->projectType = $projectType;
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
        type: Types::STRING,
        length: 255,
        nullable: false
    )]
    private string $name;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    #[ORM\Column(
        type: Types::STRING,
        length: 2048,
        nullable: false
    )]
    private string $gitUrl;

    public function getGitUrl(): string
    {
        return $this->gitUrl;
    }

    public function setGitUrl(string $gitUrl): void
    {
        $this->gitUrl = $gitUrl;
    }

    #[ORM\Column(
        type: Types::STRING,
        length: 1024,
        nullable: false
    )]
    private string $githubToken;

    public function getGithubToken(): string
    {
        return $this->githubToken;
    }

    public function setGithubToken(string $githubToken): void
    {
        $this->githubToken = $githubToken;
    }

    #[ORM\Column(
        type: Types::STRING,
        length: 32,
        nullable: false,
        enumType: ProjectType::class
    )]
    private ProjectType $projectType;

    public function getProjectType(): ProjectType
    {
        return $this->projectType;
    }

    public function setProjectType(ProjectType $projectType): void
    {
        $this->projectType = $projectType;
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
