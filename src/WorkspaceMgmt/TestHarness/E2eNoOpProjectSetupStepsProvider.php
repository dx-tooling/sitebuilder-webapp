<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\TestHarness;

use App\ProjectMgmt\Facade\Enum\ProjectType;
use App\WorkspaceMgmt\Infrastructure\SetupSteps\ProjectSetupStepsProviderInterface;
use App\WorkspaceMgmt\Infrastructure\SetupSteps\SetupStep;

/**
 * E2E/test: single no-op setup step so workspace setup completes quickly without npm/mise.
 */
final class E2eNoOpProjectSetupStepsProvider implements ProjectSetupStepsProviderInterface
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
            new SetupStep('E2E no-op', 'true', [], 5),
        ];
    }
}
