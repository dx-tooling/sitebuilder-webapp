<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Infrastructure\SetupSteps;

use App\ProjectMgmt\Facade\Enum\ProjectType;

/**
 * Provides setup steps for a specific project type.
 */
interface ProjectSetupStepsProviderInterface
{
    /**
     * Check if this provider supports the given project type.
     */
    public function supports(ProjectType $projectType): bool;

    /**
     * Get the setup steps to execute after cloning a workspace.
     *
     * @return list<SetupStep>
     */
    public function getSetupSteps(): array;
}
