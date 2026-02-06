<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Infrastructure\ContentEditor;

use App\ChatBasedContentEditor\Domain\Enum\ContentEditorBackend;
use App\LlmContentEditor\Facade\Dto\AgentConfigDto;
use App\LlmContentEditor\Facade\Dto\ConversationMessageDto;
use App\LlmContentEditor\Facade\Dto\EditStreamChunkDto;
use Generator;
use RuntimeException;

final class ContentEditorFacade implements ContentEditorFacadeInterface
{
    private ?string $lastCursorAgentSessionId = null;

    /**
     * @param list<ContentEditorAdapterInterface> $adapters
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
        ContentEditorBackend $backend,
        string               $workspacePath,
        string               $instruction,
        array                $previousMessages,
        string               $apiKey,
        ?AgentConfigDto      $agentConfig = null,
        ?string              $cursorAgentSessionId = null
    ): Generator {
        $adapter = $this->resolveAdapter($backend);

        $generator = $adapter->streamEditWithHistory(
            $workspacePath,
            $instruction,
            $previousMessages,
            $apiKey,
            $agentConfig,
            $cursorAgentSessionId
        );

        try {
            foreach ($generator as $chunk) {
                yield $chunk;
            }
        } finally {
            $this->lastCursorAgentSessionId = $adapter->getLastCursorAgentSessionId();
        }
    }

    public function getLastCursorAgentSessionId(): ?string
    {
        return $this->lastCursorAgentSessionId;
    }

    private function resolveAdapter(ContentEditorBackend $backend): ContentEditorAdapterInterface
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($backend)) {
                return $adapter;
            }
        }

        throw new RuntimeException('No content editor adapter registered for backend: ' . $backend->value);
    }
}
