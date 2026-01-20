<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Facade;

use EtfsCodingAgent\Service\WorkspaceToolingServiceInterface as BaseWorkspaceToolingFacadeInterface;

interface WorkspaceToolingServiceInterface extends BaseWorkspaceToolingFacadeInterface
{
    public function runQualityChecks(string $pathToFolder): string;

    public function runTests(string $pathToFolder): string;

    public function runBuild(string $pathToFolder): string;
}
