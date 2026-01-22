<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Infrastructure\SetupSteps;

use App\ProjectMgmt\Facade\Enum\ProjectType;

/**
 * Interface for the setup steps registry.
 */
interface ProjectSetupStepsRegistryInterface
{
    /**
     * Get the setup steps provider for the given project type.
     */
    public function getProvider(ProjectType $projectType): ProjectSetupStepsProviderInterface;

    /**
     * Get the setup steps for the given project type.
     *
     * @return list<SetupStep>
     */
    public function getSetupSteps(ProjectType $projectType): array;
}
