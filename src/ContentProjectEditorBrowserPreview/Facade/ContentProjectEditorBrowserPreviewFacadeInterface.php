<?php

declare(strict_types=1);

namespace App\ContentProjectEditorBrowserPreview\Facade;

use App\ContentProjectEditorBrowserPreview\Facade\Dto\BuildResultDto;

interface ContentProjectEditorBrowserPreviewFacadeInterface
{
    public function getPreviewUrl(string $projectId): ?string;

    public function refreshPreview(string $projectId): void;

    public function getPreviewContent(string $projectId, string $path): ?string;

    public function triggerBuild(string $projectId): BuildResultDto;
}
