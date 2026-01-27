<?php

declare(strict_types=1);

namespace App\CursorAgentContentEditor\Facade;

use App\CursorAgentContentEditor\Domain\Agent\ContentEditorAgent;
use App\CursorAgentContentEditor\Infrastructure\Streaming\CursorAgentStreamCollector;
use App\LlmContentEditor\Facade\Dto\AgentConfigDto;
use App\LlmContentEditor\Facade\Dto\ConversationMessageDto;
use App\LlmContentEditor\Facade\Dto\EditStreamChunkDto;
use App\WorkspaceTooling\Facade\AgentExecutionContextInterface;
use App\WorkspaceTooling\Facade\WorkspaceToolingServiceInterface;
use Generator;
use Throwable;

final class CursorAgentContentEditorFacade implements CursorAgentContentEditorFacadeInterface
{
    private ?string $lastSessionId = null;

    public function __construct(
        private readonly WorkspaceToolingServiceInterface $workspaceTooling,
        private readonly AgentExecutionContextInterface  $executionContext,
    ) {
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
        $this->lastSessionId = null;
        $collector           = new CursorAgentStreamCollector();

        $this->executionContext->setOutputCallback($collector);

        try {
            $prompt = $this->buildPrompt($instruction, $previousMessages, $cursorAgentSessionId === null);

            $agent = new ContentEditorAgent($this->workspaceTooling);
            $agent->run('/workspace', $prompt, $apiKey, $cursorAgentSessionId);

            $this->lastSessionId = $collector->getLastSessionId();

            foreach ($collector->drain() as $chunk) {
                yield $chunk;
            }

            yield new EditStreamChunkDto(
                'done',
                null,
                null,
                $collector->isSuccess(),
                $collector->getErrorMessage()
            );
        } catch (Throwable $e) {
            yield new EditStreamChunkDto('done', null, null, false, $e->getMessage());
        } finally {
            $this->executionContext->setOutputCallback(null);
        }
    }

    public function getLastSessionId(): ?string
    {
        return $this->lastSessionId;
    }

    /**
     * @param list<ConversationMessageDto> $previousMessages
     */
    private function buildPrompt(string $instruction, array $previousMessages, bool $includeWorkspaceContext): string
    {
        if ($previousMessages === []) {
            return $this->wrapInstruction($instruction, $includeWorkspaceContext);
        }

        $history = $this->formatHistory($previousMessages);

        return $this->wrapInstruction($history . "\n\n" . 'User: ' . $instruction, $includeWorkspaceContext);
    }

    private function wrapInstruction(string $instruction, bool $includeWorkspaceContext): string
    {
        if ($includeWorkspaceContext) {
            return sprintf(
                'The working folder is: %s' . "\n\n" . 'Please perform the following task: %s',
                '/workspace',
                $instruction
            );
        }

        return $instruction;
    }

    /**
     * @param list<ConversationMessageDto> $previousMessages
     */
    private function formatHistory(array $previousMessages): string
    {
        $lines = ['Conversation so far:'];

        foreach ($previousMessages as $message) {
            $lines[] = sprintf('%s: %s', $message->role, $message->contentJson);
        }

        return implode("\n", $lines);
    }
}
