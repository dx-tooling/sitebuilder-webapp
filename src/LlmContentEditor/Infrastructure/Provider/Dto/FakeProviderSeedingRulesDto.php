<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\Provider\Dto;

/**
 * DTO representing all seeding rules for the fake provider.
 */
readonly class FakeProviderSeedingRulesDto
{
    /**
     * @param list<ResponseRuleDto>         $responseRules
     * @param list<ToolCallRuleDto>         $toolCallRules
     * @param list<PostToolResponseRuleDto> $postToolResponseRules
     * @param list<ErrorRuleDto>            $errorRules
     */
    public function __construct(
        public array $responseRules = [],
        public array $toolCallRules = [],
        public array $postToolResponseRules = [],
        public array $errorRules = []
    ) {
    }
}
