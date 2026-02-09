<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Domain;

/**
 * Provides a summary of all tool calls performed so far in the current chat turn.
 *
 * The summary is built automatically by the infrastructure (not by the LLM) and
 * is appended to the system prompt before each LLM API request within the agentic
 * loop, so the model always knows what it already did — even after aggressive
 * context-window trimming removes tool-call messages from the history.
 */
interface TurnActivityProviderInterface
{
    /**
     * Returns a formatted summary of all tool calls made so far in this turn.
     * Empty string if no tool calls have been recorded yet.
     * Called before each LLM API request within the agentic loop.
     */
    public function getTurnActivitySummary(): string;
}
