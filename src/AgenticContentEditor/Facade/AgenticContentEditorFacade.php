<?php

declare(strict_types=1);

namespace App\AgenticContentEditor\Facade;

use App\AgenticContentEditor\Facade\Dto\AgentConfigDto;
use App\AgenticContentEditor\Facade\Dto\BackendModelInfoDto;
use App\AgenticContentEditor\Facade\Dto\ConversationMessageDto;
use App\AgenticContentEditor\Facade\Dto\EditStreamChunkDto;
use App\AgenticContentEditor\Facade\Enum\AgenticContentEditorBackend;
use Generator;
use RuntimeException;

/**
 * Dispatcher: resolves adapter by backend and delegates. Zero backend-specific logic.
 */
final class AgenticContentEditorFacade implements AgenticContentEditorFacadeInterface
{
    /**
     * @param list<AgenticContentEditorAdapterInterface> $adapters
     */
    public function __construct(
        private readonly array $adapters
    ) {
    }

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
    ): Generator {
        $adapter = $this->resolveAdapter($backend);

        return $adapter->streamEdit(
            $workspacePath,
            $instruction,
            $previousMessages,
            $apiKey,
            $agentConfig,
            $backendSessionState,
            $locale
        );
    }

    /**
     * @param list<ConversationMessageDto> $previousMessages
     */
    public function buildAgentContextDump(
        AgenticContentEditorBackend $backend,
        string                      $instruction,
        array                       $previousMessages,
        AgentConfigDto              $agentConfig
    ): string {
        return $this->resolveAdapter($backend)->buildAgentContextDump(
            $instruction,
            $previousMessages,
            $agentConfig
        );
    }

    public function getBackendModelInfo(AgenticContentEditorBackend $backend): BackendModelInfoDto
    {
        return $this->resolveAdapter($backend)->getBackendModelInfo();
    }

    private function resolveAdapter(AgenticContentEditorBackend $backend): AgenticContentEditorAdapterInterface
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($backend)) {
                return $adapter;
            }
        }

        throw new RuntimeException('No content editor adapter registered for backend: ' . $backend->value);
    }
}
