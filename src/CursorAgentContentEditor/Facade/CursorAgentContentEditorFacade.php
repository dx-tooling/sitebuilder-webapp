<?php

declare(strict_types=1);

namespace App\CursorAgentContentEditor\Facade;

use App\CursorAgentContentEditor\Domain\Agent\ContentEditorAgent;
use App\CursorAgentContentEditor\Infrastructure\Streaming\CursorAgentStreamCollector;
use App\LlmContentEditor\Facade\Dto\AgentConfigDto;
use App\LlmContentEditor\Facade\Dto\AgentEventDto;
use App\LlmContentEditor\Facade\Dto\ConversationMessageDto;
use App\LlmContentEditor\Facade\Dto\EditStreamChunkDto;
use App\WorkspaceTooling\Facade\AgentExecutionContextInterface;
use App\WorkspaceTooling\Facade\WorkspaceToolingServiceInterface;
use Generator;
use RuntimeException;
use Throwable;

final class CursorAgentContentEditorFacade implements CursorAgentContentEditorFacadeInterface
{
    /**
     * Polling interval in microseconds (50ms).
     */
    private const int POLL_INTERVAL_US = 50_000;

    private ?string $lastSessionId = null;

    public function __construct(
        private readonly WorkspaceToolingServiceInterface $workspaceTooling,
        private readonly AgentExecutionContextInterface   $executionContext,
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
            $prompt = $this->buildPrompt(
                $instruction,
                $previousMessages,
                $cursorAgentSessionId === null,
                $agentConfig
            );

            yield new EditStreamChunkDto('event', null, new AgentEventDto('inference_start'));

            $agent   = new ContentEditorAgent($this->workspaceTooling);
            $process = $agent->startAsync('/workspace', $prompt, $apiKey, $cursorAgentSessionId);

            // Poll for chunks while the process is running
            while ($process->isRunning()) {
                // Drain any chunks that have arrived
                foreach ($collector->drain() as $chunk) {
                    yield $chunk;
                }

                // Brief sleep to avoid busy-waiting
                usleep(self::POLL_INTERVAL_US);
            }

            // Check for Docker-level errors
            $process->checkResult();

            // Drain any remaining chunks after process completes
            foreach ($collector->drain() as $chunk) {
                yield $chunk;
            }

            $this->lastSessionId = $collector->getLastSessionId();

            // Always run the build after the agent completes. The Cursor CLI cannot run shell
            // commands in headless mode, so we run the build ourselves regardless of agent success.
            yield new EditStreamChunkDto('event', null, new AgentEventDto('build_start'));
            $agentImage = $this->executionContext->getAgentImage() ?? 'node:22-slim';
            try {
                $buildOutput = $this->workspaceTooling->runBuildInWorkspace($workspacePath, $agentImage);
                yield new EditStreamChunkDto('event', null, new AgentEventDto('build_complete', null, null, $buildOutput));
            } catch (RuntimeException $e) {
                yield new EditStreamChunkDto('event', null, new AgentEventDto('build_error', null, null, null, $e->getMessage()));
            }

            yield new EditStreamChunkDto('event', null, new AgentEventDto('inference_stop'));

            yield new EditStreamChunkDto(
                'done',
                null,
                null,
                $collector->isSuccess(),
                $collector->getErrorMessage()
            );
        } catch (Throwable $e) {
            yield new EditStreamChunkDto('event', null, new AgentEventDto('inference_stop'));
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
    private function buildPrompt(
        string          $instruction,
        array           $previousMessages,
        bool            $isFirstMessage,
        ?AgentConfigDto $agentConfig
    ): string {
        $parts = [];

        // Include system instructions and workspace rules only on first message of a session
        if ($isFirstMessage) {
            $systemContext = $this->buildSystemContext($agentConfig);
            if ($systemContext !== '') {
                $parts[] = $systemContext;
            }
        }

        // Add conversation history if any
        if ($previousMessages !== []) {
            $parts[] = $this->formatHistory($previousMessages);
            $parts[] = 'User: ' . $instruction;
        } else {
            $parts[] = $this->wrapInstruction($instruction, $isFirstMessage);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Build system context including agent instructions and workspace rules.
     */
    private function buildSystemContext(?AgentConfigDto $agentConfig): string
    {
        $sections = [];

        // Add working folder info
        $sections[] = 'The working folder is: /workspace';

        // Add agent background instructions
        if ($agentConfig !== null && trim($agentConfig->backgroundInstructions) !== '') {
            $sections[] = "## Background Instructions\n" . $agentConfig->backgroundInstructions;
        }

        // Add agent step instructions
        if ($agentConfig !== null && trim($agentConfig->stepInstructions) !== '') {
            $sections[] = "## Step-by-Step Instructions\n" . $agentConfig->stepInstructions;
        }

        // Add agent output instructions
        if ($agentConfig !== null && trim($agentConfig->outputInstructions) !== '') {
            $sections[] = "## Output Instructions\n" . $agentConfig->outputInstructions;
        }

        // Add workspace rules
        $workspaceRules = $this->getWorkspaceRulesForPrompt();
        if ($workspaceRules !== '') {
            $sections[] = "## Workspace Rules\n" . $workspaceRules;
        }

        // Add critical instruction about running build
        $sections[] = "## Important: Keep Source and Dist in Sync\n" .
            "After making changes to source files in /workspace/src/, you MUST run 'npm run build' " .
            'to compile the changes to the /workspace/dist/ folder. The dist folder is what gets ' .
            'served to users, so always keep it in sync with your source changes.';

        return implode("\n\n", $sections);
    }

    /**
     * Get workspace rules formatted for the prompt.
     */
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
