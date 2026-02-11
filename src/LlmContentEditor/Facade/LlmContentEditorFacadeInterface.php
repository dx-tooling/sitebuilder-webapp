<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Facade;

use App\AgenticContentEditor\Facade\Dto\AgentConfigDto;
use App\AgenticContentEditor\Facade\Dto\ConversationMessageDto;
use App\AgenticContentEditor\Facade\Dto\EditStreamChunkDto;
use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use Generator;

interface LlmContentEditorFacadeInterface
{
    /**
     * Runs the content editor agent with conversation history support.
     * Yields streaming chunks: event, text, message (new messages to persist), progress, and done.
     *
     * @param list<ConversationMessageDto> $previousMessages Messages from earlier turns in this conversation
     * @param string                       $llmApiKey        The API key for the LLM provider (BYOK)
     * @param AgentConfigDto               $agentConfig      Agent configuration from project settings
     * @param string                       $locale           UI locale for progress messages (e.g. 'en', 'de')
     *
     * @return Generator<EditStreamChunkDto>
     */
    public function streamEditWithHistory(
        string         $workspacePath,
        string         $instruction,
        array          $previousMessages,
        string         $llmApiKey,
        AgentConfigDto $agentConfig,
        string         $locale = 'en',
    ): Generator;

    /**
     * Build a plain text dump of the full agent context as it would be sent to the LLM API.
     * Includes system prompt, conversation history, and the current user instruction.
     * Used for debugging and troubleshooting.
     *
     * @param list<ConversationMessageDto> $previousMessages Messages from earlier turns
     */
    public function buildAgentContextDump(
        string         $instruction,
        array          $previousMessages,
        AgentConfigDto $agentConfig
    ): string;

    /**
     * Verifies that an API key is valid for the given provider.
     * Makes a minimal API call to check authentication.
     *
     * @return bool True if the key is valid, false otherwise
     */
    public function verifyApiKey(LlmModelProvider $provider, string $apiKey): bool;
}
