<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Facade;

use App\LlmContentEditor\Facade\Dto\AgentConfigDto;
use App\LlmContentEditor\Facade\Dto\ConversationMessageDto;
use App\LlmContentEditor\Facade\Dto\EditStreamChunkDto;
use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
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
     * @param string                       $llmApiKey        The API key for the LLM provider (BYOK)
     * @param AgentConfigDto               $agentConfig      Agent configuration from project settings
     *
     * @return Generator<EditStreamChunkDto>
     */
    public function streamEditWithHistory(
        string         $workspacePath,
        string         $instruction,
        array          $previousMessages,
        string         $llmApiKey,
        AgentConfigDto $agentConfig
    ): Generator;

    /**
     * Verifies that an API key is valid for the given provider.
     * Makes a minimal API call to check authentication.
     *
     * @return bool True if the key is valid, false otherwise
     */
    public function verifyApiKey(LlmModelProvider $provider, string $apiKey): bool;
}
