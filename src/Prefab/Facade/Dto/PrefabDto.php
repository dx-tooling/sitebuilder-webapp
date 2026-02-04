<?php

declare(strict_types=1);

namespace App\Prefab\Facade\Dto;

/**
 * Data for one prefabricated project (from prefabs.yaml).
 * Used when creating projects for a new organization.
 */
final readonly class PrefabDto
{
    public function __construct(
        public string $name,
        public string $projectLink,
        public string $githubAccessKey,
        public string $llmModelProvider,
        public string $llmApiKey,
        public bool   $keysVisible = true,
    ) {
    }
}
