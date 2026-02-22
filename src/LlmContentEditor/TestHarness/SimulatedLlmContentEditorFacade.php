<?php

declare(strict_types=1);

namespace App\LlmContentEditor\TestHarness;

use App\LlmContentEditor\Facade\Dto\AgentConfigDto;
use App\LlmContentEditor\Facade\Dto\AgentEventDto;
use App\LlmContentEditor\Facade\Dto\ConversationMessageDto;
use App\LlmContentEditor\Facade\Dto\EditStreamChunkDto;
use App\LlmContentEditor\Facade\Dto\ToolInputEntryDto;
use App\LlmContentEditor\Facade\Enum\EditStreamChunkType;
use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\LlmContentEditor\Facade\LlmContentEditorFacadeInterface;
use Closure;
use Generator;
use JsonException;

use function json_encode;
use function sprintf;
use function trim;

use const JSON_THROW_ON_ERROR;

final class SimulatedLlmContentEditorFacade implements LlmContentEditorFacadeInterface
{
    private const string ERROR_MARKER = '[simulate_error]';
    private const string TOOL_MARKER  = '[simulate_tool]';

    /**
     * @return Generator<EditStreamChunkDto>
     */
    public function streamEdit(string $workspacePath, string $instruction): Generator
    {
        yield from $this->streamEditWithHistory(
            $workspacePath,
            $instruction,
            [],
            'simulated-api-key',
            new AgentConfigDto('', '', '', '/workspace'),
            'en',
        );
    }

    /**
     * @param list<ConversationMessageDto> $previousMessages
     *
     * @return Generator<EditStreamChunkDto>
     *
     * @throws JsonException
     */
    public function streamEditWithHistory(
        string         $workspacePath,
        string         $instruction,
        array          $previousMessages,
        string         $llmApiKey,
        AgentConfigDto $agentConfig,
        string         $locale = 'en',
        ?Closure       $isCancelled = null,
    ): Generator {
        $normalizedInstruction = trim($instruction);

        yield new EditStreamChunkDto(
            EditStreamChunkType::Message,
            null,
            null,
            null,
            null,
            new ConversationMessageDto('user', json_encode(['content' => $normalizedInstruction], JSON_THROW_ON_ERROR))
        );

        yield new EditStreamChunkDto(
            EditStreamChunkType::Event,
            null,
            new AgentEventDto('inference_start'),
            null,
            null
        );
        yield new EditStreamChunkDto(EditStreamChunkType::Progress, 'Analyzing instruction...');

        if (str_contains($normalizedInstruction, self::TOOL_MARKER)) {
            $toolInputs = [
                new ToolInputEntryDto('path', '/workspace/src/index.html'),
                new ToolInputEntryDto('replacement', 'Updated headline'),
            ];

            yield new EditStreamChunkDto(
                EditStreamChunkType::Event,
                null,
                new AgentEventDto(
                    'tool_calling',
                    'replace_in_file',
                    $toolInputs,
                    null,
                    null,
                    48,
                    null
                ),
                null,
                null
            );
            yield new EditStreamChunkDto(EditStreamChunkType::Progress, 'Applying tool changes...');

            yield new EditStreamChunkDto(
                EditStreamChunkType::Event,
                null,
                new AgentEventDto(
                    'tool_called',
                    'replace_in_file',
                    null,
                    'Successfully replaced text in /workspace/src/index.html',
                    null,
                    null,
                    56
                ),
                null,
                null
            );
        }

        if (str_contains($normalizedInstruction, self::ERROR_MARKER)) {
            yield new EditStreamChunkDto(
                EditStreamChunkType::Event,
                null,
                new AgentEventDto('agent_error', null, null, null, 'Simulated provider failure'),
                null,
                null
            );
            yield new EditStreamChunkDto(EditStreamChunkType::Done, null, null, false, 'Simulated provider failure');

            return;
        }

        $assistantText = sprintf('Simulated edit completed for instruction: %s', $normalizedInstruction);
        yield new EditStreamChunkDto(EditStreamChunkType::Text, $assistantText);
        yield new EditStreamChunkDto(
            EditStreamChunkType::Message,
            null,
            null,
            null,
            null,
            new ConversationMessageDto('assistant', json_encode(['content' => $assistantText], JSON_THROW_ON_ERROR))
        );
        yield new EditStreamChunkDto(
            EditStreamChunkType::Message,
            null,
            null,
            null,
            null,
            new ConversationMessageDto(
                ConversationMessageDto::ROLE_TURN_ACTIVITY_SUMMARY,
                json_encode(['content' => 'Simulated turn summary generated for testing.'], JSON_THROW_ON_ERROR)
            )
        );
        yield new EditStreamChunkDto(
            EditStreamChunkType::Event,
            null,
            new AgentEventDto('inference_stop'),
            null,
            null
        );
        yield new EditStreamChunkDto(EditStreamChunkType::Done, null, null, true, null);
    }

    /**
     * @param list<ConversationMessageDto> $previousMessages
     */
    public function buildAgentContextDump(string $instruction, array $previousMessages, AgentConfigDto $agentConfig): string
    {
        return sprintf(
            "=== SIMULATED AGENT CONTEXT ===\nWorking folder: %s\nMessages: %d\nInstruction: %s",
            $agentConfig->workingFolderPath ?? '/workspace',
            count($previousMessages),
            $instruction
        );
    }

    public function verifyApiKey(LlmModelProvider $provider, string $apiKey): bool
    {
        return $apiKey !== '';
    }
}
