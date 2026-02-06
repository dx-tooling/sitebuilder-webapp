<?php

declare(strict_types=1);

namespace App\CursorAgentContentEditor\Domain\Agent;

use App\WorkspaceTooling\Facade\StreamingProcessInterface;
use App\WorkspaceTooling\Facade\WorkspaceToolingServiceInterface;
use EtfsCodingAgent\Agent\BaseCodingAgent;

class ContentEditorAgent extends BaseCodingAgent
{
    public function __construct(
        private readonly WorkspaceToolingServiceInterface $workspaceTooling
    ) {
        parent::__construct($workspaceTooling);
    }

    public function run(
        string  $workingDirectory,
        string  $prompt,
        string  $apiKey,
        ?string $resumeSessionId = null
    ): string {
        $command = $this->buildCommand($prompt, $apiKey, $resumeSessionId);

        return $this->workspaceTooling->runShellCommand($workingDirectory, $command);
    }

    /**
     * Start the agent asynchronously for streaming output.
     *
     * Returns a StreamingProcessInterface that can be polled for completion.
     * Output is streamed to any configured callback as it arrives.
     */
    public function startAsync(
        string  $workingDirectory,
        string  $prompt,
        string  $apiKey,
        ?string $resumeSessionId = null
    ): StreamingProcessInterface {
        $command = $this->buildCommand($prompt, $apiKey, $resumeSessionId);

        return $this->workspaceTooling->runShellCommandAsync($workingDirectory, $command);
    }

    private function buildCommand(string $prompt, string $apiKey, ?string $resumeSessionId): string
    {
        $sessionArg = '';
        if ($resumeSessionId !== null && $resumeSessionId !== '') {
            $sessionArg = '--resume ' . escapeshellarg($resumeSessionId);
        }

        // Create a script that sets up PATH with mise node installation, then set BASH_ENV to source it.
        // The Cursor CLI spawns fresh bash processes for shellToolCall that don't inherit environment
        // variables from the parent. BASH_ENV tells non-interactive bash to source this file first.
        return sprintf(
            'echo \'export PATH="/opt/mise/data/installs/node/24.13.0/bin:$PATH"\' > /etc/profile.d/mise-path.sh && ' .
            'export BASH_ENV=/etc/profile.d/mise-path.sh && ' .
            'AGENT_BIN=%s; ' .
            'if [ ! -x "$AGENT_BIN" ]; then echo "agent not found" >&2; exit 127; fi; ' .
            '"$AGENT_BIN" --output-format stream-json --stream-partial-output --force %s --api-key %s -p %s',
            escapeshellarg('/root/.local/bin/agent'),
            $sessionArg,
            escapeshellarg($apiKey),
            escapeshellarg($prompt)
        );
    }
}
