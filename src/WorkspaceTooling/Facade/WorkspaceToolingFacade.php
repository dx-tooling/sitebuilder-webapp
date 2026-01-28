<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Facade;

use App\RemoteContentAssets\Facade\RemoteContentAssetsFacadeInterface;
use App\WorkspaceTooling\Infrastructure\Execution\AgentExecutionContext;
use EtfsCodingAgent\Service\FileOperationsServiceInterface;
use EtfsCodingAgent\Service\ShellOperationsServiceInterface;
use EtfsCodingAgent\Service\TextOperationsService;
use EtfsCodingAgent\Service\WorkspaceToolingService as BaseWorkspaceToolingFacade;
use Symfony\Component\Finder\Finder;
use Throwable;

final class WorkspaceToolingFacade extends BaseWorkspaceToolingFacade implements WorkspaceToolingServiceInterface
{
    public function __construct(
        FileOperationsServiceInterface                      $fileOperationsService,
        TextOperationsService                               $textOperationsService,
        ShellOperationsServiceInterface                     $shellOperationsService,
        private readonly AgentExecutionContext              $executionContext,
        private readonly RemoteContentAssetsFacadeInterface $remoteContentAssetsFacade
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
        $manifestUrls = $this->executionContext->getRemoteContentAssetsManifestUrls();
        if ($manifestUrls === []) {
            return '[]';
        }

        try {
            $urls = $this->remoteContentAssetsFacade->fetchAndMergeAssetUrls($manifestUrls);

            return json_encode($urls, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return '[]';
        }
    }

    public function searchRemoteContentAssetUrls(string $regexPattern): string
    {
        $manifestUrls = $this->executionContext->getRemoteContentAssetsManifestUrls();
        if ($manifestUrls === []) {
            return '[]';
        }

        // Validate regex pattern by attempting a test match
        $fullPattern = '#' . $regexPattern . '#i';
        if (!$this->isValidRegexPattern($fullPattern)) {
            return json_encode(['error' => 'Invalid regex pattern: ' . $regexPattern], JSON_THROW_ON_ERROR);
        }

        try {
            $urls = $this->remoteContentAssetsFacade->fetchAndMergeAssetUrls($manifestUrls);
        } catch (Throwable) {
            return '[]';
        }

        $matchingUrls = [];
        foreach ($urls as $url) {
            $filename = $this->extractFilenameFromUrl($url);
            if (preg_match($fullPattern, $filename) === 1) {
                $matchingUrls[] = $url;
            }
        }

        return json_encode($matchingUrls, JSON_THROW_ON_ERROR);
    }

    private function extractFilenameFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path === null || $path === false) {
            return '';
        }

        return basename($path);
    }

    private function isValidRegexPattern(string $pattern): bool
    {
        set_error_handler(static fn () => true);
        try {
            $result = preg_match($pattern, '');

            return $result !== false;
        } finally {
            restore_error_handler();
        }
    }

    public function getRemoteAssetInfo(string $url): string
    {
        $info = $this->remoteContentAssetsFacade->getRemoteAssetInfo($url);
        if ($info === null) {
            return '{"error":"Could not retrieve asset info"}';
        }
        $payload = [
            'url'         => $info->url,
            'width'       => $info->width,
            'height'      => $info->height,
            'mimeType'    => $info->mimeType,
            'sizeInBytes' => $info->sizeInBytes,
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    public function getWorkspaceRules(): string
    {
        $workspacePath = $this->executionContext->getWorkspacePath();
        if ($workspacePath === null || !is_dir($workspacePath)) {
            return '{}';
        }

        $rules = [];

        try {
            // Find all .sitebuilder/rules directories in the workspace
            $dirFinder = new Finder();
            $dirFinder->directories()
                ->in($workspacePath)
                ->path('/\.sitebuilder\/rules$/')
                ->ignoreVCS(true)
                ->ignoreDotFiles(false);

            foreach ($dirFinder as $rulesDir) {
                $rulesDirPath = $rulesDir->getRealPath();
                if ($rulesDirPath === false) {
                    continue;
                }

                // Find all .md files in this rules directory
                $fileFinder = new Finder();
                $fileFinder->files()
                    ->in($rulesDirPath)
                    ->name('*.md')
                    ->depth(0);

                foreach ($fileFinder as $file) {
                    $ruleName = $file->getBasename('.md');
                    // Use first found rule if duplicate names exist
                    if (!array_key_exists($ruleName, $rules)) {
                        $rules[$ruleName] = $file->getContents();
                    }
                }
            }
        } catch (Throwable) {
            return '{}';
        }

        return json_encode($rules, JSON_THROW_ON_ERROR);
    }
}
