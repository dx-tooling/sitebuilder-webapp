<?php

declare(strict_types=1);

namespace App\ContentProjectEditor\Facade;

use App\ContentProjectEditor\Facade\Dto\EditorSessionDto;
use App\ContentProjectEditor\Facade\Dto\ExportResultDto;

interface ContentProjectEditorFacadeInterface
{
    public function startEditorSession(string $projectId, string $userId): EditorSessionDto;

    public function getEditorSession(string $sessionId): ?EditorSessionDto;

    public function endEditorSession(string $sessionId): void;

    public function exportProject(string $projectId): ExportResultDto;
}
