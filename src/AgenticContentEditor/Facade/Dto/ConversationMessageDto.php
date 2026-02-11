<?php

declare(strict_types=1);

namespace App\AgenticContentEditor\Facade\Dto;

/**
 * DTO representing a message in a conversation for cross-vertical communication.
 * The role maps to NeuronAI message types (user, assistant, tool_call, tool_call_result)
 * or to automatically generated turn activity summaries (turn_activity_summary).
 *
 * @param 'user'|'assistant'|'turn_activity_summary'|'tool_call'|'tool_call_result' $role
 */
readonly class ConversationMessageDto
{
    public const string ROLE_TURN_ACTIVITY_SUMMARY = 'turn_activity_summary';

    public function __construct(
        public string $role,
        public string $contentJson,
    ) {
    }
}
