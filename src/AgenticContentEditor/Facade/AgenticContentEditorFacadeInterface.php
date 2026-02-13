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
 * Port: what consumers (e.g. ChatBasedContentEditor) call.
 * Backend is passed as a parameter; no backend-specific branching at call sites.
 */
interface AgenticContentEditorFacadeInterface
{
    /**
     * @param list<ConversationMessageDto> $previousMessages
     *
     * @return Generator<EditStreamChunkDto>
     */
    public function streamEditWithHistory(
        AgenticContentEditorBackend $backend,
        string                      $workspacePath,
        string                      $instruction,
        array                       $previousMessages,
        string                      $apiKey,
        AgentConfigDto              $agentConfig,
        ?string                     $backendSessionState = null,
        string                      $locale = 'en',
    ): Generator;

    /**
     * Build a human-readable dump of the full agent context for debugging.
     * Dispatches to the adapter matching the given backend.
     *
     * @param list<ConversationMessageDto> $previousMessages
     */
    public function buildAgentContextDump(
        AgenticContentEditorBackend $backend,
        string                      $instruction,
        array                       $previousMessages,
        AgentConfigDto              $agentConfig
    ): string;

    /**
     * Return model information for the given backend (name, context limit, cost rates).
     * Used for context usage bars and cost estimation in the UI.
     */
    public function getBackendModelInfo(AgenticContentEditorBackend $backend): BackendModelInfoDto;
}
