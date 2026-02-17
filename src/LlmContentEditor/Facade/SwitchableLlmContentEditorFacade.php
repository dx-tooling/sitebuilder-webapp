<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Facade;

use App\LlmContentEditor\Facade\Dto\AgentConfigDto;
use App\LlmContentEditor\Facade\Dto\ConversationMessageDto;
use App\LlmContentEditor\Facade\Dto\EditStreamChunkDto;
use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\LlmContentEditor\TestHarness\SimulatedLlmContentEditorFacade;
use Generator;
use Psr\Log\LoggerInterface;

final readonly class SwitchableLlmContentEditorFacade implements LlmContentEditorFacadeInterface
{
    public function __construct(
        private LlmContentEditorFacade          $realFacade,
        private SimulatedLlmContentEditorFacade $simulatedFacade,
        private LoggerInterface                 $logger,
        private bool                            $simulate,
    ) {
        if ($this->simulate) {
            $this->logger->warning('LLM chat simulation is enabled. External LLM API calls are bypassed.');
        }
    }

    /**
     * @return Generator<EditStreamChunkDto>
     */
    public function streamEdit(string $workspacePath, string $instruction): Generator
    {
        return $this->activeFacade()->streamEdit($workspacePath, $instruction);
    }

    /**
     * @param list<ConversationMessageDto> $previousMessages
     *
     * @return Generator<EditStreamChunkDto>
     */
    public function streamEditWithHistory(
        string         $workspacePath,
        string         $instruction,
        array          $previousMessages,
        string         $llmApiKey,
        AgentConfigDto $agentConfig,
        string         $locale = 'en',
    ): Generator {
        return $this->activeFacade()->streamEditWithHistory(
            $workspacePath,
            $instruction,
            $previousMessages,
            $llmApiKey,
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
        return $this->activeFacade()->buildAgentContextDump($instruction, $previousMessages, $agentConfig);
    }

    public function verifyApiKey(LlmModelProvider $provider, string $apiKey): bool
    {
        return $this->activeFacade()->verifyApiKey($provider, $apiKey);
    }

    private function activeFacade(): LlmContentEditorFacadeInterface
    {
        if ($this->simulate) {
            return $this->simulatedFacade;
        }

        return $this->realFacade;
    }
}
