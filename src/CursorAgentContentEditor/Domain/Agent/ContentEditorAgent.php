<?php

declare(strict_types=1);

namespace App\CursorAgentContentEditor\Domain\Agent;

use App\WorkspaceTooling\Facade\WorkspaceToolingServiceInterface;
use EtfsCodingAgent\Agent\BaseCodingAgent;

class ContentEditorAgent extends BaseCodingAgent
{
    public function __construct(
        WorkspaceToolingServiceInterface $workspaceToolingFacade
    ) {
        parent::__construct($workspaceToolingFacade);
    }

    public function run(
        string  $workingDirectory,
        string  $prompt,
        string  $apiKey,
        ?string $resumeSessionId = null
    ): string {
        $sessionArg = '';
        if ($resumeSessionId !== null && $resumeSessionId !== '') {
            $sessionArg = '--resume ' . escapeshellarg($resumeSessionId);
        }

        $command = sprintf(
            'AGENT_BIN=%s; ' .
            'if [ ! -x "$AGENT_BIN" ]; then echo "agent not found" >&2; exit 127; fi; ' .
            '"$AGENT_BIN" --output-format stream-json --stream-partial-output %s --api-key %s -p %s',
            escapeshellarg('/root/.local/bin/agent'),
            $sessionArg,
            escapeshellarg($apiKey),
            escapeshellarg($prompt)
        );

        return $this->workspaceToolingFacade->runShellCommand($workingDirectory, $command);
    }
}
