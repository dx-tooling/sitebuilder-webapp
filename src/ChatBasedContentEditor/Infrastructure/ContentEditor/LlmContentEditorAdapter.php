<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Infrastructure\ContentEditor;

use App\ChatBasedContentEditor\Domain\Enum\ContentEditorBackend;
use App\LlmContentEditor\Facade\Dto\AgentConfigDto;
use App\LlmContentEditor\Facade\Dto\ConversationMessageDto;
use App\LlmContentEditor\Facade\Dto\EditStreamChunkDto;
use App\LlmContentEditor\Facade\LlmContentEditorFacadeInterface;
use Generator;

final class LlmContentEditorAdapter implements ContentEditorAdapterInterface
{
    public function __construct(
        private readonly LlmContentEditorFacadeInterface $llmContentEditorFacade
    ) {
    }

    public function supports(ContentEditorBackend $backend): bool
    {
        return $backend === ContentEditorBackend::Llm;
    }

    /**
     * @param list<ConversationMessageDto> $previousMessages
     *
     * @return Generator<EditStreamChunkDto>
     */
    public function streamEditWithHistory(
        string          $workspacePath,
        string          $instruction,
        array           $previousMessages,
        string          $apiKey,
        ?AgentConfigDto $agentConfig = null,
        ?string         $cursorAgentSessionId = null
    ): Generator {
        return $this->llmContentEditorFacade->streamEditWithHistory(
            $workspacePath,
            $instruction,
            $previousMessages,
            $apiKey,
            $agentConfig
        );
    }

    public function getLastCursorAgentSessionId(): ?string
    {
        return null;
    }
}
