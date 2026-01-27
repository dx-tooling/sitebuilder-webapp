<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Infrastructure\ContentEditor;

use App\ChatBasedContentEditor\Domain\Enum\ContentEditorBackend;
use App\CursorAgentContentEditor\Facade\CursorAgentContentEditorFacadeInterface;
use App\LlmContentEditor\Facade\Dto\AgentConfigDto;
use App\LlmContentEditor\Facade\Dto\ConversationMessageDto;
use App\LlmContentEditor\Facade\Dto\EditStreamChunkDto;
use Generator;

final class CursorAgentContentEditorAdapter implements ContentEditorAdapterInterface
{
    public function __construct(
        private readonly CursorAgentContentEditorFacadeInterface $cursorAgentContentEditorFacade
    ) {
    }

    public function supports(ContentEditorBackend $backend): bool
    {
        return $backend === ContentEditorBackend::CursorAgent;
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
        return $this->cursorAgentContentEditorFacade->streamEditWithHistory(
            $workspacePath,
            $instruction,
            $previousMessages,
            $apiKey,
            $agentConfig,
            $cursorAgentSessionId
        );
    }

    public function getLastCursorAgentSessionId(): ?string
    {
        return $this->cursorAgentContentEditorFacade->getLastSessionId();
    }
}
