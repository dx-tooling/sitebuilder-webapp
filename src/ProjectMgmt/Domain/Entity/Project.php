<?php

declare(strict_types=1);

namespace App\ProjectMgmt\Domain\Entity;

use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\ProjectMgmt\Domain\ValueObject\AgentConfigTemplate;
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
    public const string DEFAULT_AGENT_IMAGE = 'node:22-slim';

    /**
     * @throws Exception
     */
    /**
     * @param list<string>|null $remoteContentAssetsManifestUrls
     */
    public function __construct(
        string           $name,
        string           $gitUrl,
        string           $githubToken,
        LlmModelProvider $llmModelProvider,
        string           $llmApiKey,
        ProjectType      $projectType = ProjectType::DEFAULT,
        string           $agentImage = self::DEFAULT_AGENT_IMAGE,
        ?string          $agentBackgroundInstructions = null,
        ?string          $agentStepInstructions = null,
        ?string          $agentOutputInstructions = null,
        ?array           $remoteContentAssetsManifestUrls = null
    ) {
        $this->name                            = $name;
        $this->gitUrl                          = $gitUrl;
        $this->githubToken                     = $githubToken;
        $this->llmModelProvider                = $llmModelProvider;
        $this->llmApiKey                       = $llmApiKey;
        $this->projectType                     = $projectType;
        $this->agentImage                      = $agentImage;
        $this->createdAt                       = DateAndTimeService::getDateTimeImmutable();
        $this->remoteContentAssetsManifestUrls = $remoteContentAssetsManifestUrls !== null && $remoteContentAssetsManifestUrls !== [] ? $remoteContentAssetsManifestUrls : null;

        // Initialize agent config from template if not provided
        $template                          = AgentConfigTemplate::forProjectType($projectType);
        $this->agentBackgroundInstructions = $agentBackgroundInstructions ?? $template->backgroundInstructions;
        $this->agentStepInstructions       = $agentStepInstructions       ?? $template->stepInstructions;
        $this->agentOutputInstructions     = $agentOutputInstructions     ?? $template->outputInstructions;
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
        type: Types::STRING,
        length: 255,
        nullable: false,
        options: ['default' => self::DEFAULT_AGENT_IMAGE]
    )]
    private string $agentImage = self::DEFAULT_AGENT_IMAGE;

    public function getAgentImage(): string
    {
        return $this->agentImage;
    }

    public function setAgentImage(string $agentImage): void
    {
        $this->agentImage = $agentImage;
    }

    #[ORM\Column(
        type: Types::STRING,
        length: 32,
        nullable: false,
        enumType: LlmModelProvider::class
    )]
    private LlmModelProvider $llmModelProvider;

    public function getLlmModelProvider(): LlmModelProvider
    {
        return $this->llmModelProvider;
    }

    public function setLlmModelProvider(LlmModelProvider $llmModelProvider): void
    {
        $this->llmModelProvider = $llmModelProvider;
    }

    #[ORM\Column(
        type: Types::STRING,
        length: 1024,
        nullable: false
    )]
    private string $llmApiKey;

    public function getLlmApiKey(): string
    {
        return $this->llmApiKey;
    }

    public function setLlmApiKey(string $llmApiKey): void
    {
        $this->llmApiKey = $llmApiKey;
    }

    #[ORM\Column(
        type: Types::TEXT,
        nullable: false
    )]
    private string $agentBackgroundInstructions;

    public function getAgentBackgroundInstructions(): string
    {
        return $this->agentBackgroundInstructions;
    }

    public function setAgentBackgroundInstructions(string $agentBackgroundInstructions): void
    {
        $this->agentBackgroundInstructions = $agentBackgroundInstructions;
    }

    #[ORM\Column(
        type: Types::TEXT,
        nullable: false
    )]
    private string $agentStepInstructions;

    public function getAgentStepInstructions(): string
    {
        return $this->agentStepInstructions;
    }

    public function setAgentStepInstructions(string $agentStepInstructions): void
    {
        $this->agentStepInstructions = $agentStepInstructions;
    }

    #[ORM\Column(
        type: Types::TEXT,
        nullable: false
    )]
    private string $agentOutputInstructions;

    public function getAgentOutputInstructions(): string
    {
        return $this->agentOutputInstructions;
    }

    public function setAgentOutputInstructions(string $agentOutputInstructions): void
    {
        $this->agentOutputInstructions = $agentOutputInstructions;
    }

    /**
     * @var list<string>|null
     */
    #[ORM\Column(
        type: Types::JSON,
        nullable: true
    )]
    private ?array $remoteContentAssetsManifestUrls = null;

    /**
     * @return list<string>
     */
    public function getRemoteContentAssetsManifestUrls(): array
    {
        return $this->remoteContentAssetsManifestUrls ?? [];
    }

    /**
     * @param list<string> $remoteContentAssetsManifestUrls
     */
    public function setRemoteContentAssetsManifestUrls(array $remoteContentAssetsManifestUrls): void
    {
        $this->remoteContentAssetsManifestUrls = $remoteContentAssetsManifestUrls === [] ? null : $remoteContentAssetsManifestUrls;
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
    private ?DateTimeImmutable $deletedAt = null;

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function markAsDeleted(): void
    {
        $this->deletedAt = DateAndTimeService::getDateTimeImmutable();
    }

    public function restore(): void
    {
        $this->deletedAt = null;
    }
}
