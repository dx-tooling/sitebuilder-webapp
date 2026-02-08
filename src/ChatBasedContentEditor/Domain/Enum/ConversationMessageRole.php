<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Domain\Enum;

/**
 * Represents the role of a message in a conversation.
 * These map to NeuronAI's MessageRole enum values.
 */
enum ConversationMessageRole: string
{
    case User           = 'user';
    case Assistant      = 'assistant';
    case AssistantNote  = 'assistant_note';
    case ToolCall       = 'tool_call';
    case ToolCallResult = 'tool_call_result';
}
