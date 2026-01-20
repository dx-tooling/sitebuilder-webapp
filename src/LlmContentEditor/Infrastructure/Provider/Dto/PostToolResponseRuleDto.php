<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\Provider\Dto;

use NeuronAI\Chat\Messages\AssistantMessage;

/**
 * DTO representing a post-tool response rule for the fake provider.
 */
readonly class PostToolResponseRuleDto
{
    public function __construct(
        public string                  $pattern,
        public string|AssistantMessage $content
    ) {
    }
}
