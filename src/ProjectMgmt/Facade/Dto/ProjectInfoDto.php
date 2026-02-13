<?php

declare(strict_types=1);

namespace App\ProjectMgmt\Facade\Dto;

use App\AgenticContentEditor\Facade\Enum\AgenticContentEditorBackend;
use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\ProjectMgmt\Facade\Enum\ProjectType;

final readonly class ProjectInfoDto
{
    /**
     * @param list<string> $remoteContentAssetsManifestUrls
     */
    public function __construct(
        public string                      $id,
        public string                      $name,
        public string                      $gitUrl,
        public string                      $githubToken,
        public ProjectType                 $projectType,
        public AgenticContentEditorBackend $contentEditorBackend,
        public string                      $githubUrl,
        public string                      $agentImage,
        public LlmModelProvider            $contentEditingLlmModelProvider,
        public string                      $contentEditingApiKey,
        public string                      $agentBackgroundInstructions,
        public string                      $agentStepInstructions,
        public string                      $agentOutputInstructions,
        public array                       $remoteContentAssetsManifestUrls = [],
        // S3 Upload Configuration (all optional)
        public ?string                     $s3BucketName = null,
        public ?string                     $s3Region = null,
        public ?string                     $s3AccessKeyId = null,
        public ?string                     $s3SecretAccessKey = null,
        public ?string                     $s3IamRoleArn = null,
        public ?string                     $s3KeyPrefix = null,
        // PhotoBuilder LLM Configuration (nullable = falls back to content editing)
        public ?LlmModelProvider           $photoBuilderLlmModelProvider = null,
        public ?string                     $photoBuilderApiKey = null,
    ) {
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

    /**
     * Returns the effective PhotoBuilder provider (dedicated or content editing fallback).
     */
    public function getEffectivePhotoBuilderLlmModelProvider(): LlmModelProvider
    {
        return $this->photoBuilderLlmModelProvider ?? $this->contentEditingLlmModelProvider;
    }

    /**
     * Returns the effective PhotoBuilder API key (dedicated or content editing fallback).
     */
    public function getEffectivePhotoBuilderApiKey(): string
    {
        return $this->photoBuilderApiKey ?? $this->contentEditingApiKey;
    }
}
