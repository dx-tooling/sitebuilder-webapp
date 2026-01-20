<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Facade;

use App\LlmContentEditor\Facade\Dto\ConversationMessageDto;
use App\LlmContentEditor\Facade\Dto\EditStreamChunkDto;
use Generator;

interface LlmContentEditorFacadeInterface
{
    /**
     * Runs the content editor agent on the given workspace with the given instruction.
     * Yields streaming chunks: event (structured agent feedback), text (LLM output), and done.
     * The caller is responsible for resolving and validating workspacePath (e.g. under an allowed root).
     *
     * @deprecated Use streamEditWithHistory() to support multi-turn conversations
     *
     * @return Generator<EditStreamChunkDto>
     */
    public function streamEdit(string $workspacePath, string $instruction): Generator;

    /**
     * Runs the content editor agent with conversation history support.
     * Yields streaming chunks: event, text, message (new messages to persist), and done.
     *
     * @param list<ConversationMessageDto> $previousMessages Messages from earlier turns in this conversation
     *
     * @return Generator<EditStreamChunkDto>
     */
    public function streamEditWithHistory(
        string $workspacePath,
        string $instruction,
        array  $previousMessages = []
    ): Generator;
}
