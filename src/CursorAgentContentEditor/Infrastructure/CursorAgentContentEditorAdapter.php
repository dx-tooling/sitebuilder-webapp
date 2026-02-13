<?php

declare(strict_types=1);

namespace App\CursorAgentContentEditor\Infrastructure;

use App\AgenticContentEditor\Facade\AgenticContentEditorAdapterInterface;
use App\AgenticContentEditor\Facade\Dto\AgentConfigDto;
use App\AgenticContentEditor\Facade\Dto\AgentEventDto;
use App\AgenticContentEditor\Facade\Dto\BackendModelInfoDto;
use App\AgenticContentEditor\Facade\Dto\ConversationMessageDto;
use App\AgenticContentEditor\Facade\Dto\EditStreamChunkDto;
use App\AgenticContentEditor\Facade\Enum\AgenticContentEditorBackend;
use App\AgenticContentEditor\Facade\Enum\EditStreamChunkType;
use App\CursorAgentContentEditor\Domain\Agent\ContentEditorAgent;
use App\CursorAgentContentEditor\Infrastructure\Streaming\CursorAgentStreamCollector;
use App\WorkspaceTooling\Facade\AgentExecutionContextInterface;
use App\WorkspaceTooling\Facade\WorkspaceToolingServiceInterface;
use Generator;
use RuntimeException;
use Throwable;

final class CursorAgentContentEditorAdapter implements AgenticContentEditorAdapterInterface
{
    /**
     * Polling interval in microseconds (50ms).
     */
    private const int POLL_INTERVAL_US = 50_000;

    public function __construct(
        private readonly WorkspaceToolingServiceInterface $workspaceTooling,
        private readonly AgentExecutionContextInterface   $executionContext,
    ) {
    }

    public function supports(AgenticContentEditorBackend $backend): bool
    {
        return $backend === AgenticContentEditorBackend::CursorAgent;
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
        $collector = new CursorAgentStreamCollector();
        $this->executionContext->setOutputCallback($collector);

        try {
            $prompt = $this->buildPrompt(
                $instruction,
                $previousMessages,
                $backendSessionState === null,
                $agentConfig
            );

            yield new EditStreamChunkDto(EditStreamChunkType::Event, null, new AgentEventDto('inference_start'));

            $agent   = new ContentEditorAgent($this->workspaceTooling);
            $process = $agent->startAsync('/workspace', $prompt, $apiKey, $backendSessionState);

            // Poll for chunks while the process is running
            while ($process->isRunning()) {
                foreach ($collector->drain() as $chunk) {
                    yield $chunk;
                }

                usleep(self::POLL_INTERVAL_US);
            }

            // Check for Docker-level errors
            $process->checkResult();

            // Drain any remaining chunks after process completes
            foreach ($collector->drain() as $chunk) {
                yield $chunk;
            }

            $lastSessionId = $collector->getLastSessionId();

            // Always run the build after the agent completes. The Cursor CLI cannot run shell
            // commands in headless mode, so we run the build ourselves regardless of agent success.
            yield new EditStreamChunkDto(EditStreamChunkType::Event, null, new AgentEventDto('tool_calling', 'run_build'));
            $agentImage = $this->executionContext->getAgentImage() ?? 'node:22-slim';

            try {
                $buildOutput = $this->workspaceTooling->runBuildInWorkspace($workspacePath, $agentImage);
                yield new EditStreamChunkDto(EditStreamChunkType::Event, null, new AgentEventDto('tool_called', 'run_build', null, $buildOutput));
            } catch (RuntimeException $e) {
                yield new EditStreamChunkDto(EditStreamChunkType::Event, null, new AgentEventDto('tool_error', 'run_build', null, null, $e->getMessage()));
            }

            yield new EditStreamChunkDto(EditStreamChunkType::Event, null, new AgentEventDto('inference_stop'));

            yield new EditStreamChunkDto(
                EditStreamChunkType::Done,
                null,
                null,
                $collector->isSuccess(),
                $collector->getErrorMessage(),
                null,
                $lastSessionId
            );
        } catch (Throwable $e) {
            yield new EditStreamChunkDto(EditStreamChunkType::Event, null, new AgentEventDto('inference_stop'));
            yield new EditStreamChunkDto(EditStreamChunkType::Done, null, null, false, $e->getMessage());
        } finally {
            $this->executionContext->setOutputCallback(null);
        }
    }

    public function getBackendModelInfo(): BackendModelInfoDto
    {
        // The Cursor agent manages its own model and context internally.
        // We report the effective prompt limit for context-bar purposes.
        // Cost rates are null because Cursor pricing is opaque (not per-token BYOK).
        return new BackendModelInfoDto(
            'cursor-agent',
            200_000,
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
        $isFirstMessage = $previousMessages === [];

        $lines   = [];
        $lines[] = '=== CURSOR AGENT CONTEXT ===';
        $lines[] = '';

        if ($isFirstMessage) {
            $lines[] = '--- SYSTEM CONTEXT ---';
            $lines[] = $this->buildSystemContext($agentConfig);
            $lines[] = '';
        }

        if ($previousMessages !== []) {
            $lines[] = '--- CONVERSATION HISTORY ---';
            $lines[] = $this->formatHistory($previousMessages);
            $lines[] = '';
        }

        $lines[] = '--- CURRENT PROMPT ---';
        $lines[] = $this->buildPrompt($instruction, $previousMessages, $isFirstMessage, $agentConfig);

        return implode("\n", $lines);
    }

    /**
     * @param list<ConversationMessageDto> $previousMessages
     */
    private function buildPrompt(
        string         $instruction,
        array          $previousMessages,
        bool           $isFirstMessage,
        AgentConfigDto $agentConfig
    ): string {
        $parts = [];

        if ($isFirstMessage) {
            $systemContext = $this->buildSystemContext($agentConfig);
            if ($systemContext !== '') {
                $parts[] = $systemContext;
            }
        }

        if ($previousMessages !== []) {
            $parts[] = $this->formatHistory($previousMessages);
            $parts[] = 'User: ' . $instruction;
        } else {
            $parts[] = $this->wrapInstruction($instruction, $isFirstMessage);
        }

        return implode("\n\n", $parts);
    }

    private function buildSystemContext(AgentConfigDto $agentConfig): string
    {
        $sections = [];

        $sections[] = 'The working folder is: /workspace';

        if (trim($agentConfig->backgroundInstructions) !== '') {
            $sections[] = "## Background Instructions\n" . $agentConfig->backgroundInstructions;
        }

        if (trim($agentConfig->stepInstructions) !== '') {
            $sections[] = "## Step-by-Step Instructions\n" . $agentConfig->stepInstructions;
        }

        if (trim($agentConfig->outputInstructions) !== '') {
            $sections[] = "## Output Instructions\n" . $agentConfig->outputInstructions;
        }

        $workspaceRules = $this->getWorkspaceRulesForPrompt();
        if ($workspaceRules !== '') {
            $sections[] = "## Workspace Rules\n" . $workspaceRules;
        }

        $sections[] = "## Important: Keep Source and Dist in Sync\n" .
            "After making changes to source files in /workspace/src/, you MUST run 'npm run build' " .
            'to compile the changes to the /workspace/dist/ folder. The dist folder is what gets ' .
            'served to users, so always keep it in sync with your source changes.';

        return implode("\n\n", $sections);
    }

    private function getWorkspaceRulesForPrompt(): string
    {
        $rulesJson = $this->workspaceTooling->getWorkspaceRules();
        $rules     = json_decode($rulesJson, true);

        if (!is_array($rules) || $rules === []) {
            return '';
        }

        $formatted = [];
        foreach ($rules as $ruleName => $ruleContent) {
            if (!is_string($ruleName) || !is_string($ruleContent)) {
                continue;
            }
            $formatted[] = "### {$ruleName}\n{$ruleContent}";
        }

        return implode("\n\n", $formatted);
    }

    private function wrapInstruction(string $instruction, bool $includeWorkspaceContext): string
    {
        if ($includeWorkspaceContext) {
            return 'Please perform the following task: ' . $instruction;
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
