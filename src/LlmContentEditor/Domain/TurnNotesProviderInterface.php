<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Domain;

/**
 * Chat history that can provide accumulated notes from write_note_to_self within the current turn.
 * Used to append a "summary of what you have done so far" to the system prompt on each API request.
 */
interface TurnNotesProviderInterface
{
    /**
     * Returns all notes from write_note_to_self in this turn, formatted for the system prompt.
     * Empty string if none. Called before each LLM request within the same turn.
     */
    public function getAccumulatedTurnNotes(): string;
}
