<?php

declare(strict_types=1);

namespace App\ProjectMgmt\Facade\Dto;

/**
 * DTO representing an existing LLM API key with its abbreviated form
 * and the projects that use it. Used for the "reuse existing key" feature.
 */
final readonly class ExistingLlmApiKeyDto
{
    /**
     * @param list<string> $projectNames
     */
    public function __construct(
        public string $apiKey,
        public string $abbreviatedKey,
        public array  $projectNames,
    ) {
    }
}
