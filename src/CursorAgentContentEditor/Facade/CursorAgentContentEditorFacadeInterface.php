<?php

declare(strict_types=1);

namespace App\CursorAgentContentEditor\Facade;

use App\LlmContentEditor\Facade\Dto\AgentConfigDto;
use App\LlmContentEditor\Facade\Dto\ConversationMessageDto;
use App\LlmContentEditor\Facade\Dto\EditStreamChunkDto;
use Generator;

interface CursorAgentContentEditorFacadeInterface
{
    /**
     * Runs the cursor agent with optional conversation history.
     *
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
    ): Generator;

    public function getLastSessionId(): ?string;
}
