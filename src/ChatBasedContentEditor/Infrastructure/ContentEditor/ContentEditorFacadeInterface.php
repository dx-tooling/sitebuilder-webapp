<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Infrastructure\ContentEditor;

use App\ChatBasedContentEditor\Domain\Enum\ContentEditorBackend;
use App\LlmContentEditor\Facade\Dto\AgentConfigDto;
use App\LlmContentEditor\Facade\Dto\ConversationMessageDto;
use App\LlmContentEditor\Facade\Dto\EditStreamChunkDto;
use Generator;

interface ContentEditorFacadeInterface
{
    /**
     * @param list<ConversationMessageDto> $previousMessages
     *
     * @return Generator<EditStreamChunkDto>
     */
    public function streamEditWithHistory(
        ContentEditorBackend $backend,
        string               $workspacePath,
        string               $instruction,
        array                $previousMessages,
        string               $apiKey,
        ?AgentConfigDto      $agentConfig = null,
        ?string              $cursorAgentSessionId = null
    ): Generator;

    public function getLastCursorAgentSessionId(): ?string;
}
