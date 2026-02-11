<?php

declare(strict_types=1);

namespace App\AgenticContentEditor\Facade;

use App\AgenticContentEditor\Facade\Dto\AgentConfigDto;
use App\AgenticContentEditor\Facade\Dto\BackendModelInfoDto;
use App\AgenticContentEditor\Facade\Dto\ConversationMessageDto;
use App\AgenticContentEditor\Facade\Dto\EditStreamChunkDto;
use App\AgenticContentEditor\Facade\Enum\AgenticContentEditorBackend;
use Generator;

/**
 * SPI: what backends (LlmContentEditor, CursorAgentContentEditor) implement.
 * Adapters yield Done chunks with optional backendSessionState for resumable backends.
 */
interface AgenticContentEditorAdapterInterface
{
    public function supports(AgenticContentEditorBackend $backend): bool;

    /**
     * @param list<ConversationMessageDto> $previousMessages
     *
     * @return Generator<EditStreamChunkDto>
     */
    public function streamEdit(
        string         $workspacePath,
        string         $instruction,
        array          $previousMessages,
        string         $apiKey,
        AgentConfigDto $agentConfig,
        ?string        $backendSessionState = null,
        string         $locale = 'en',
    ): Generator;

    /**
     * Build a human-readable dump of the full agent context for debugging.
     * Each backend formats this according to how it actually sends context to its agent.
     *
     * @param list<ConversationMessageDto> $previousMessages
     */
    public function buildAgentContextDump(
        string         $instruction,
        array          $previousMessages,
        AgentConfigDto $agentConfig
    ): string;

    /**
     * Return model information for this backend (name, context limit, cost rates).
     * Used for context usage bars and cost estimation in the UI.
     */
    public function getBackendModelInfo(): BackendModelInfoDto;
}
