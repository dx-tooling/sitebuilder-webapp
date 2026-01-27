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
    ): string
    {
        $agentBinary = $_ENV['CURSOR_AGENT_BINARY'] ?? 'agent';
        if (!is_string($agentBinary) || $agentBinary === '') {
            $agentBinary = 'agent';
        }

        $sessionArg = '';
        if ($resumeSessionId !== null && $resumeSessionId !== '') {
            $sessionArg = '--resume ' . escapeshellarg($resumeSessionId);
        }

        $command = sprintf(
            '%s --output-format stream-json --stream-partial-output %s --api-key %s -p %s',
            escapeshellcmd($agentBinary),
            $sessionArg,
            escapeshellarg($apiKey),
            escapeshellarg($prompt)
        );

        return $this->workspaceToolingFacade->runShellCommand($workingDirectory, $command);
    }
}
