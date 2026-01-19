<?php

declare(strict_types=1);

namespace App\ContentProjectVersioning\Facade;

use App\ContentProjectVersioning\Facade\Dto\DiffDto;
use App\ContentProjectVersioning\Facade\Dto\VersionDto;

interface ContentProjectVersioningFacadeInterface
{
    public function createVersion(string $projectId, string $message): VersionDto;

    /**
     * @return list<VersionDto>
     */
    public function getVersionHistory(string $projectId): array;

    public function getVersion(string $versionId): ?VersionDto;

    public function rollbackToVersion(string $projectId, string $versionId): void;

    public function getCurrentVersion(string $projectId): ?VersionDto;

    public function getVersionDiff(string $versionId): ?DiffDto;
}
