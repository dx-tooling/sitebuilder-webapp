<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Facade;

use App\WorkspaceTooling\Infrastructure\Execution\AgentExecutionContext;
use App\WorkspaceTooling\Infrastructure\RemoteManifestFetcher;
use EtfsCodingAgent\Service\FileOperationsServiceInterface;
use EtfsCodingAgent\Service\ShellOperationsServiceInterface;
use EtfsCodingAgent\Service\TextOperationsService;
use EtfsCodingAgent\Service\WorkspaceToolingService as BaseWorkspaceToolingFacade;
use Throwable;

final class WorkspaceToolingFacade extends BaseWorkspaceToolingFacade implements WorkspaceToolingServiceInterface
{
    public function __construct(
        FileOperationsServiceInterface         $fileOperationsService,
        TextOperationsService                  $textOperationsService,
        ShellOperationsServiceInterface        $shellOperationsService,
        private readonly AgentExecutionContext $executionContext,
        private readonly RemoteManifestFetcher $remoteManifestFetcher
    ) {
        parent::__construct(
            $fileOperationsService,
            $textOperationsService,
            $shellOperationsService
        );
    }

    public function runQualityChecks(string $pathToFolder): string
    {
        return $this->shellOperationsService->runCommand(
            $pathToFolder,
            'npm run quality'
        );
    }

    public function runTests(string $pathToFolder): string
    {
        return $this->shellOperationsService->runCommand(
            $pathToFolder,
            'npm run test'
        );
    }

    public function runBuild(string $pathToFolder): string
    {
        return $this->shellOperationsService->runCommand(
            $pathToFolder,
            'npm run build'
        );
    }

    public function applyV4aDiffToFile(string $pathToFile, string $v4aDiff): string
    {
        $modifiedContent = $this->textOperationsService->applyDiffToFile($pathToFile, $v4aDiff);
        $this->fileOperationsService->writeFileContent($pathToFile, $modifiedContent);
        $lineCount = substr_count($modifiedContent, "\n") + 1;

        return "Applied. File now has {$lineCount} lines.";
    }

    public function suggestCommitMessage(string $message): string
    {
        $this->executionContext->setSuggestedCommitMessage($message);

        return 'Commit message recorded: ' . $message;
    }

    public function getPreviewUrl(string $sandboxPath): string
    {
        $workspaceId = $this->executionContext->getWorkspaceId();

        if ($workspaceId === null) {
            return 'Error: Execution context not set. Cannot generate preview URL.';
        }

        // Normalize the path: remove leading/trailing whitespace
        $path = trim($sandboxPath);

        // Strip the /workspace prefix (the Docker mount point)
        $workspacePrefix = '/workspace/';
        if (str_starts_with($path, $workspacePrefix)) {
            $relativePath = substr($path, strlen($workspacePrefix));
        } elseif (str_starts_with($path, '/workspace')) {
            // Handle /workspace without trailing slash (e.g., /workspacefoo should not match)
            $relativePath = substr($path, strlen('/workspace'));
            $relativePath = ltrim($relativePath, '/');
        } else {
            // Path doesn't start with /workspace - could be already relative
            $relativePath = ltrim($path, '/');
        }

        // Security check: prevent path traversal
        if (str_contains($relativePath, '..')) {
            return 'Error: Invalid path - path traversal not allowed.';
        }

        // Normalize double slashes and remove leading slash
        $relativePath = preg_replace('#/+#', '/', $relativePath) ?? $relativePath;
        $relativePath = ltrim($relativePath, '/');

        // Build the browser preview URL
        return '/workspaces/' . $workspaceId . '/' . $relativePath;
    }

    public function listRemoteContentAssetUrls(): string
    {
        $manifestUrls = $this->executionContext->getContentAssetsManifestUrls();
        if ($manifestUrls === []) {
            return '[]';
        }

        try {
            $urls = $this->remoteManifestFetcher->fetchAndMergeAssetUrls($manifestUrls);

            return json_encode($urls, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return '[]';
        }
    }
}
