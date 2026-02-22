<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Facade;

use App\RemoteContentAssets\Facade\RemoteContentAssetsFacadeInterface;
use App\WorkspaceTooling\Infrastructure\Execution\AgentExecutionContext;
use App\WorkspaceTooling\Infrastructure\Execution\DockerExecutor;
use EtfsCodingAgent\Service\FileOperationsServiceInterface;
use EtfsCodingAgent\Service\ShellOperationsServiceInterface;
use EtfsCodingAgent\Service\TextOperationsService;
use EtfsCodingAgent\Service\WorkspaceToolingService as BaseWorkspaceToolingFacade;
use Symfony\Component\Finder\Finder;
use Throwable;

final class WorkspaceToolingFacade extends BaseWorkspaceToolingFacade implements WorkspaceToolingServiceInterface
{
    private const string WORKSPACE_MOUNT_POINT                = '/workspace';
    private const string CURL_META_MARKER                     = '__PB_CURL_META__';
    private const int REMOTE_WEB_PAGE_MAX_BYTES               = 50_000;
    private const int REMOTE_WEB_PAGE_TIMEOUT_SECONDS         = 20;
    private const int REMOTE_WEB_PAGE_CONNECT_TIMEOUT_SECONDS = 10;

    public function __construct(
        FileOperationsServiceInterface                      $fileOperationsService,
        TextOperationsService                               $textOperationsService,
        ShellOperationsServiceInterface                     $shellOperationsService,
        private readonly AgentExecutionContext              $executionContext,
        private readonly RemoteContentAssetsFacadeInterface $remoteContentAssetsFacade,
        private readonly DockerExecutor                     $dockerExecutor
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
            if (preg_match($fullPattern, $url) === 1) {
                $matchingUrls[] = $url;
            }
        }

        return json_encode($matchingUrls, JSON_THROW_ON_ERROR);
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

    public function fetchRemoteWebPage(string $url): string
    {
        $normalizedUrl = trim($url);
        if (!$this->isAllowedRemoteWebPageUrl($normalizedUrl)) {
            return $this->encodeRemoteWebPageError(
                'Invalid URL. Only absolute http/https URLs are supported.',
                $normalizedUrl
            );
        }

        $workspacePath = $this->executionContext->getWorkspacePath();
        if ($workspacePath === null || $workspacePath === '' || !is_dir($workspacePath)) {
            return $this->encodeRemoteWebPageError(
                'Execution context not set. Cannot resolve workspace path for cURL fetch.',
                $normalizedUrl
            );
        }

        try {
            $output = $this->shellOperationsService->runCommand(
                self::WORKSPACE_MOUNT_POINT,
                $this->buildFetchRemoteWebPageCommand($normalizedUrl)
            );
        } catch (Throwable $throwable) {
            return $this->encodeRemoteWebPageError(
                'Failed to fetch remote page: ' . $throwable->getMessage(),
                $normalizedUrl
            );
        }

        $markerPos = strrpos($output, self::CURL_META_MARKER);
        if ($markerPos === false) {
            return $this->encodeRemoteWebPageError(
                'cURL output did not contain expected metadata.',
                $normalizedUrl
            );
        }

        $contentEnd = $markerPos;
        if ($contentEnd > 0 && $output[$contentEnd - 1] === "\n") {
            --$contentEnd;
        }

        $content = substr($output, 0, $contentEnd);
        $metaRaw = trim(substr($output, $markerPos + strlen(self::CURL_META_MARKER)));

        if (!preg_match('/^(\d{3})\t([^\t]*)\t(\S+)/', $metaRaw, $matches)) {
            return $this->encodeRemoteWebPageError(
                'Failed to parse metadata from cURL output.',
                $normalizedUrl
            );
        }

        $statusCode  = (int) $matches[1];
        $contentType = $matches[2];
        $finalUrl    = $matches[3];

        $truncated = false;
        if (strlen($content) > self::REMOTE_WEB_PAGE_MAX_BYTES) {
            $content   = substr($content, 0, self::REMOTE_WEB_PAGE_MAX_BYTES);
            $truncated = true;
        }

        return $this->encodeJsonSafe([
            'url'         => $normalizedUrl,
            'finalUrl'    => $finalUrl,
            'statusCode'  => $statusCode,
            'contentType' => $contentType,
            'content'     => $content,
            'truncated'   => $truncated,
        ]);
    }

    private function buildFetchRemoteWebPageCommand(string $url): string
    {
        $escapedUrl     = escapeshellarg($url);
        $writeOutFormat = escapeshellarg('\n' . self::CURL_META_MARKER . '%{http_code}\t%{content_type}\t%{url_effective}');

        return sprintf(
            'curl -L -sS --max-time %d --connect-timeout %d --output - --write-out %s %s',
            self::REMOTE_WEB_PAGE_TIMEOUT_SECONDS,
            self::REMOTE_WEB_PAGE_CONNECT_TIMEOUT_SECONDS,
            $writeOutFormat,
            $escapedUrl
        );
    }

    private function isAllowedRemoteWebPageUrl(string $url): bool
    {
        $parsed = parse_url($url);
        if (!is_array($parsed)) {
            return false;
        }

        $scheme = $parsed['scheme'] ?? null;
        $host   = $parsed['host']   ?? null;
        if (!is_string($scheme) || !is_string($host)) {
            return false;
        }

        if (!in_array(strtolower($scheme), ['http', 'https'], true)) {
            return false;
        }

        return $host !== '';
    }

    private function encodeRemoteWebPageError(string $error, string $url): string
    {
        return $this->encodeJsonSafe([
            'error' => $error,
            'url'   => $url,
        ]);
    }

    /**
     * @param array<string, bool|int|string|null> $payload
     */
    private function encodeJsonSafe(array $payload): string
    {
        $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($json)) {
            return $json;
        }

        return '{"error":"Unable to encode JSON response.","url":""}';
    }

    public function runBuildInWorkspace(string $workspacePath, string $agentImage): string
    {
        return $this->dockerExecutor->run(
            $agentImage,
            'npm run build',
            $workspacePath,
            '/workspace',
            300,
            true,
            'html-editor-build'
        );
    }
}
