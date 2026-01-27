<?php

declare(strict_types=1);

namespace App\ProjectMgmt\Facade\Dto;

use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\ProjectMgmt\Facade\Enum\ContentEditorBackend;
use App\ProjectMgmt\Facade\Enum\ProjectType;

final readonly class ProjectInfoDto
{
    /**
     * @param list<string> $remoteContentAssetsManifestUrls
     */
    public function __construct(
        public string               $id,
        public string               $name,
        public string               $gitUrl,
        public string               $githubToken,
        public ProjectType          $projectType,
        public ContentEditorBackend $contentEditorBackend,
        public string               $githubUrl,
        public string               $agentImage,
        public LlmModelProvider     $llmModelProvider,
        public string               $llmApiKey,
        public string               $agentBackgroundInstructions,
        public string               $agentStepInstructions,
        public string               $agentOutputInstructions,
        public array                $remoteContentAssetsManifestUrls = [],
    ) {
    }
}
