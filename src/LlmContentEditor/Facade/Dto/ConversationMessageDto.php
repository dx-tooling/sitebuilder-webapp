<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Facade\Dto;

use NeuronAI\Tools\Tool;

use function array_key_exists;
use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * DTO representing a message in a conversation for cross-vertical communication.
 * The role maps to NeuronAI message types (user, assistant, tool_call, tool_call_result)
 * or to internal assistant note-to-self messages (assistant_note_to_self).
 */
readonly class ConversationMessageDto
{
    public const string ROLE_ASSISTANT_NOTE_TO_SELF = 'assistant_note_to_self';

    /** Tool name used by the agent for assistant note-to-self (must match ContentEditorAgent). */
    public const string TOOL_NAME_WRITE_NOTE_TO_SELF = 'write_note_to_self';

    /**
     * @param 'user'|'assistant'|'assistant_note_to_self'|'tool_call'|'tool_call_result' $role
     */
    public function __construct(
        public string $role,
        public string $contentJson,
    ) {
    }

    /**
     * Build an assistant note-to-self DTO from a tool call, or null if not applicable.
     */
    public static function fromWriteNoteToSelfTool(Tool $tool): ?self
    {
        if ($tool->getName() !== self::TOOL_NAME_WRITE_NOTE_TO_SELF) {
            return null;
        }

        $inputs = $tool->getInputs();
        $note   = array_key_exists('note', $inputs) && is_string($inputs['note']) ? $inputs['note'] : '';
        if ($note === '') {
            return null;
        }

        return new self(
            self::ROLE_ASSISTANT_NOTE_TO_SELF,
            json_encode(['content' => $note], JSON_THROW_ON_ERROR)
        );
    }
}
