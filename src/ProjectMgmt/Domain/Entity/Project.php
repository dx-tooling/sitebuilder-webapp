<?php

declare(strict_types=1);

namespace App\ProjectMgmt\Domain\Entity;

use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\ProjectMgmt\Domain\ValueObject\AgentConfigTemplate;
use App\ProjectMgmt\Facade\Enum\ContentEditorBackend;
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
        string               $organizationId,
        string               $name,
        string               $gitUrl,
        string               $githubToken,
        LlmModelProvider     $llmModelProvider,
        string               $llmApiKey,
        ProjectType          $projectType = ProjectType::DEFAULT,
        ContentEditorBackend $contentEditorBackend = ContentEditorBackend::Llm,
        string               $agentImage = self::DEFAULT_AGENT_IMAGE,
        ?string              $agentBackgroundInstructions = null,
        ?string              $agentStepInstructions = null,
        ?string              $agentOutputInstructions = null,
        ?array               $remoteContentAssetsManifestUrls = null
    ) {
        $this->organizationId                  = $organizationId;
        $this->name                            = $name;
        $this->gitUrl                          = $gitUrl;
        $this->githubToken                     = $githubToken;
        $this->llmModelProvider                = $llmModelProvider;
        $this->llmApiKey                       = $llmApiKey;
        $this->projectType                     = $projectType;
        $this->contentEditorBackend            = $contentEditorBackend;
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
        type: Types::GUID,
        nullable: false
    )]
    private readonly string $organizationId;

    public function getOrganizationId(): string
    {
        return $this->organizationId;
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

    // S3 Upload Configuration (all optional)

    #[ORM\Column(
        type: Types::STRING,
        length: 255,
        nullable: true
    )]
    private ?string $s3BucketName = null;

    public function getS3BucketName(): ?string
    {
        return $this->s3BucketName;
    }

    public function setS3BucketName(?string $s3BucketName): void
    {
        $this->s3BucketName = $s3BucketName;
    }

    #[ORM\Column(
        type: Types::STRING,
        length: 64,
        nullable: true
    )]
    private ?string $s3Region = null;

    public function getS3Region(): ?string
    {
        return $this->s3Region;
    }

    public function setS3Region(?string $s3Region): void
    {
        $this->s3Region = $s3Region;
    }

    #[ORM\Column(
        type: Types::STRING,
        length: 256,
        nullable: true
    )]
    private ?string $s3AccessKeyId = null;

    public function getS3AccessKeyId(): ?string
    {
        return $this->s3AccessKeyId;
    }

    public function setS3AccessKeyId(?string $s3AccessKeyId): void
    {
        $this->s3AccessKeyId = $s3AccessKeyId;
    }

    #[ORM\Column(
        type: Types::STRING,
        length: 256,
        nullable: true
    )]
    private ?string $s3SecretAccessKey = null;

    public function getS3SecretAccessKey(): ?string
    {
        return $this->s3SecretAccessKey;
    }

    public function setS3SecretAccessKey(?string $s3SecretAccessKey): void
    {
        $this->s3SecretAccessKey = $s3SecretAccessKey;
    }

    #[ORM\Column(
        type: Types::STRING,
        length: 2048,
        nullable: true
    )]
    private ?string $s3IamRoleArn = null;

    public function getS3IamRoleArn(): ?string
    {
        return $this->s3IamRoleArn;
    }

    public function setS3IamRoleArn(?string $s3IamRoleArn): void
    {
        $this->s3IamRoleArn = $s3IamRoleArn;
    }

    #[ORM\Column(
        type: Types::STRING,
        length: 1024,
        nullable: true
    )]
    private ?string $s3KeyPrefix = null;

    public function getS3KeyPrefix(): ?string
    {
        return $this->s3KeyPrefix;
    }

    public function setS3KeyPrefix(?string $s3KeyPrefix): void
    {
        $this->s3KeyPrefix = $s3KeyPrefix;
    }

    /**
     * Check if S3 upload is configured (all required fields present).
     */
    public function hasS3UploadConfigured(): bool
    {
        return $this->s3BucketName      !== null
            && $this->s3Region          !== null
            && $this->s3AccessKeyId     !== null
            && $this->s3SecretAccessKey !== null;
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
        type: Types::BOOLEAN,
        nullable: true
    )]
    private ?bool $keysVisible = null;

    public function isKeysVisible(): bool
    {
        return $this->keysVisible ?? true;
    }

    public function setKeysVisible(bool $keysVisible): void
    {
        $this->keysVisible = $keysVisible;
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
