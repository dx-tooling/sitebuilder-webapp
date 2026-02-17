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
        public string  $name,
        public string  $projectLink,
        public string  $githubAccessKey,
        public string  $contentEditingLlmModelProvider,
        public string  $contentEditingLlmApiKey,
        public bool    $keysVisible = true,
        public ?string $photoBuilderLlmModelProvider = null,
        public ?string $photoBuilderLlmApiKey = null,
    ) {
    }
}
