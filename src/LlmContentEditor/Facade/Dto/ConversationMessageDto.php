<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Facade\Dto;

/**
 * DTO representing a message in a conversation for cross-vertical communication.
 * The role maps to NeuronAI message types (user, assistant, tool_call, tool_call_result)
 * or to internal turn activity summaries (assistant_note_to_self).
 */
readonly class ConversationMessageDto
{
    public const string ROLE_ASSISTANT_NOTE_TO_SELF = 'assistant_note_to_self';

    /**
     * @param 'user'|'assistant'|'assistant_note_to_self'|'tool_call'|'tool_call_result' $role
     */
    public function __construct(
        public string $role,
        public string $contentJson,
    ) {
    }
}
