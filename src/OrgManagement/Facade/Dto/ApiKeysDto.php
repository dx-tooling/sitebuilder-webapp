<?php

declare(strict_types=1);

namespace App\OrgManagement\Facade\Dto;

readonly class ApiKeysDto
{
    public function __construct(
        public ?string $gitHubToken,
        public ?string $llmApiKey,
        public ?string $llmProvider,
    ) {
    }
}
