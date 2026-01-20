<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Facade\Dto;

/**
 * DTO representing a message in a conversation for cross-vertical communication.
 * The role maps to NeuronAI message types (user, assistant, tool_call, tool_call_result).
 */
readonly class ConversationMessageDto
{
    /**
     * @param 'user'|'assistant'|'tool_call'|'tool_call_result' $role
     */
    public function __construct(
        public string $role,
        public string $contentJson,
    ) {
    }
}
