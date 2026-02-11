<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure;

use App\AgenticContentEditor\Facade\AgenticContentEditorAdapterInterface;
use App\AgenticContentEditor\Facade\Dto\AgentConfigDto;
use App\AgenticContentEditor\Facade\Dto\BackendModelInfoDto;
use App\AgenticContentEditor\Facade\Dto\ConversationMessageDto;
use App\AgenticContentEditor\Facade\Dto\EditStreamChunkDto;
use App\AgenticContentEditor\Facade\Enum\AgenticContentEditorBackend;
use App\LlmContentEditor\Domain\Enum\LlmModelName;
use App\LlmContentEditor\Facade\LlmContentEditorFacadeInterface;
use Generator;

final class LlmContentEditorAdapter implements AgenticContentEditorAdapterInterface
{
    public function __construct(
        private readonly LlmContentEditorFacadeInterface $llmContentEditorFacade
    ) {
    }

    public function supports(AgenticContentEditorBackend $backend): bool
    {
        return $backend === AgenticContentEditorBackend::Llm;
    }

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
    ): Generator {
        return $this->llmContentEditorFacade->streamEditWithHistory(
            $workspacePath,
            $instruction,
            $previousMessages,
            $apiKey,
            $agentConfig,
            $locale
        );
    }

    /**
     * @param list<ConversationMessageDto> $previousMessages
     */
    public function buildAgentContextDump(
        string         $instruction,
        array          $previousMessages,
        AgentConfigDto $agentConfig
    ): string {
        return $this->llmContentEditorFacade->buildAgentContextDump(
            $instruction,
            $previousMessages,
            $agentConfig
        );
    }

    public function getBackendModelInfo(): BackendModelInfoDto
    {
        $model = LlmModelName::defaultForContentEditor();

        return new BackendModelInfoDto(
            $model->value,
            $model->maxContextTokens(),
            $model->inputCostPer1M(),
            $model->outputCostPer1M()
        );
    }
}
