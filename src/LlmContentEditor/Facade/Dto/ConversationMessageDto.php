<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Facade\Dto;

/**
 * DTO representing a message in a conversation for cross-vertical communication.
 * The role maps to NeuronAI message types (user, assistant, tool_call, tool_call_result)
 * or to internal note-to-self messages (assistant_note).
 */
readonly class ConversationMessageDto
{
    /**
     * @param 'user'|'assistant'|'assistant_note'|'tool_call'|'tool_call_result' $role
     */
    public function __construct(
        public string $role,
        public string $contentJson,
    ) {
    }
}
