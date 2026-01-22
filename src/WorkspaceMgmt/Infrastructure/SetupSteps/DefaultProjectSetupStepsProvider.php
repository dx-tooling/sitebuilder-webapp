<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Infrastructure\SetupSteps;

use App\ProjectMgmt\Facade\Enum\ProjectType;

/**
 * Provides setup steps for the DEFAULT project type.
 * Trusts mise configuration, then runs npm install and npm build via mise exec.
 */
final class DefaultProjectSetupStepsProvider implements ProjectSetupStepsProviderInterface
{
    public function supports(ProjectType $projectType): bool
    {
        return match ($projectType) {
            ProjectType::DEFAULT => true,
        };
    }

    /**
     * @return list<SetupStep>
     */
    public function getSetupSteps(): array
    {
        return [
            new SetupStep(
                'Trust mise configuration',
                'mise',
                ['trust'],
                10
            ),
            new SetupStep(
                'Install npm dependencies',
                'mise',
                ['exec', '--', 'npm', 'install', '--no-save'],
                300 // 5 minutes
            ),
            new SetupStep(
                'Build project',
                'mise',
                ['exec', '--', 'npm', 'run', 'build'],
                300 // 5 minutes
            ),
        ];
    }
}
