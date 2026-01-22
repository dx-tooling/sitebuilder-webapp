<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Infrastructure\SetupSteps;

use App\ProjectMgmt\Facade\Enum\ProjectType;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Registry that collects all setup steps providers and returns the appropriate one.
 */
final class ProjectSetupStepsRegistry implements ProjectSetupStepsRegistryInterface
{
    /**
     * @var list<ProjectSetupStepsProviderInterface>
     */
    private readonly array $providers;

    /**
     * @param iterable<ProjectSetupStepsProviderInterface> $providers
     */
    public function __construct(
        #[TaggedIterator('workspace_mgmt.setup_steps_provider')]
        iterable $providers
    ) {
        $this->providers = array_values(iterator_to_array($providers));
    }

    /**
     * Get the setup steps provider for the given project type.
     *
     * @throws RuntimeException if no provider is found for the project type
     */
    public function getProvider(ProjectType $projectType): ProjectSetupStepsProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($projectType)) {
                return $provider;
            }
        }

        throw new RuntimeException(
            sprintf('No setup steps provider found for project type: %s', $projectType->value)
        );
    }

    /**
     * Get the setup steps for the given project type.
     *
     * @return list<SetupStep>
     *
     * @throws RuntimeException if no provider is found for the project type
     */
    public function getSetupSteps(ProjectType $projectType): array
    {
        return $this->getProvider($projectType)->getSetupSteps();
    }
}
