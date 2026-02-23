<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Domain\Agent;

use App\LlmContentEditor\Domain\Enum\LlmModelName;
use App\LlmContentEditor\Domain\TurnActivityProviderInterface;
use App\LlmContentEditor\Facade\Dto\AgentConfigDto;
use App\LlmContentEditor\Infrastructure\WireLog\LlmWireLogMiddleware;
use App\WorkspaceTooling\Facade\WorkspaceToolingServiceInterface;
use EtfsCodingAgent\Agent\BaseCodingAgent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HttpClientOptions;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Psr\Log\LoggerInterface;

class ContentEditorAgent extends BaseCodingAgent
{
    public function __construct(
        private readonly WorkspaceToolingServiceInterface $sitebuilderFacade,
        private readonly LlmModelName                     $model,
        private readonly string                           $apiKey,
        private readonly AgentConfigDto                   $agentConfig,
        private readonly ?LoggerInterface                 $wireLogger = null,
    ) {
        parent::__construct($sitebuilderFacade);
    }

    protected function provider(): AIProviderInterface
    {
        $httpOptions = null;

        if ($this->wireLogger !== null) {
            $httpOptions = new HttpClientOptions(
                null,
                null,
                null,
                LlmWireLogMiddleware::createHandlerStack($this->wireLogger),
            );
        }

        return new OpenAI(
            $this->apiKey,
            $this->model->value,
            [],
            false,
            $httpOptions,
        );
    }

    /**
     * System prompt includes working folder path when set, so it survives context-window trimming.
     * When the chat history has a TurnActivityJournal, the journal summary is appended so the model
     * always knows what tool calls it has already made — even after aggressive context-window trimming
     * removes the actual tool-call messages from the history.
     *
     * Called before each LLM API request within the agentic loop (each recursive stream() call).
     *
     * @see https://github.com/dx-tooling/sitebuilder-webapp/issues/79
     * @see https://github.com/dx-tooling/sitebuilder-webapp/issues/83
     */
    public function instructions(): string
    {
        $base = parent::instructions();
        if ($this->agentConfig->workingFolderPath !== null && $this->agentConfig->workingFolderPath !== '') {
            $base .= "\n\nWORKING FOLDER (use for all path-based tools): " . $this->agentConfig->workingFolderPath;
        }

        $history = $this->resolveChatHistory();
        if ($history instanceof TurnActivityProviderInterface) {
            $summary = $history->getTurnActivitySummary();
            if ($summary !== '') {
                $base .= "\n\n---\nACTIONS PERFORMED SO FAR THIS TURN:\n" . $summary;
            }
        }

        return $base;
    }

    /**
     * @return list<string>
     */
    protected function getBackgroundInstructions(): array
    {
        return explode("\n", $this->agentConfig->backgroundInstructions);
    }

    /**
     * @return list<string>
     */
    protected function getStepInstructions(): array
    {
        return explode("\n", $this->agentConfig->stepInstructions);
    }

    /**
     * @return list<string>
     */
    protected function getOutputInstructions(): array
    {
        return explode("\n", $this->agentConfig->outputInstructions);
    }

    /**
     * @return list<\NeuronAI\Tools\ToolInterface>
     */
    protected function tools(): array
    {
        return array_merge(parent::tools(), [
            Tool::make(
                'run_quality_checks',
                'Run quality checks (npm run quality) in the workspace to verify code standards and linting. Returns the command output.',
            )->addProperty(
                new ToolProperty(
                    'path',
                    PropertyType::STRING,
                    'The absolute path to the workspace folder where quality checks should run.',
                    true
                )
            )->setCallable(fn (string $path): string => $this->sitebuilderFacade->runQualityChecks($path)),

            Tool::make(
                'run_tests',
                'Run the test suite (npm run test) in the workspace to verify functionality. Returns the test output.',
            )->addProperty(
                new ToolProperty(
                    'path',
                    PropertyType::STRING,
                    'The absolute path to the workspace folder where tests should run.',
                    true
                )
            )->setCallable(fn (string $path): string => $this->sitebuilderFacade->runTests($path)),

            Tool::make(
                'run_build',
                'Build the workspace (npm run build) from source. Returns the build output.',
            )->addProperty(
                new ToolProperty(
                    'path',
                    PropertyType::STRING,
                    'The absolute path to the workspace folder to build.',
                    true
                )
            )->setCallable(fn (string $path): string => $this->sitebuilderFacade->runBuild($path)),

            Tool::make(
                'suggest_commit_message',
                'Suggest an optimal git commit message describing the changes made. Call this after making file changes. The message should be a concise, conventional commit message in the same language the user is speaking (e.g., "Add hero section to homepage", "Füge Hero-Bereich zur Startseite hinzu").',
            )->addProperty(
                new ToolProperty(
                    'message',
                    PropertyType::STRING,
                    'The suggested commit message (50-72 chars, imperative mood, in the user\'s language)',
                    true
                )
            )->setCallable(fn (string $message): string => $this->sitebuilderFacade->suggestCommitMessage($message)),

            Tool::make(
                'get_preview_url',
                'Get the browser preview URL for a file in the workspace. Use this after building to provide the user with clickable links to view their changes. The tool translates workspace paths (like /workspace/dist/page.html) into browser-accessible preview URLs.',
            )->addProperty(
                new ToolProperty(
                    'path',
                    PropertyType::STRING,
                    'The path to the file as seen in the workspace (e.g., /workspace/dist/index.html or dist/about.html)',
                    true
                )
            )->setCallable(fn (string $path): string => $this->sitebuilderFacade->getPreviewUrl($path)),

            Tool::make(
                'list_remote_content_asset_urls',
                'Get the list of remote content asset URLs (images, etc.) from manifests configured for this project. Returns a JSON array of URLs that can be embedded as-is (e.g. in img src). Returns an empty array if no manifests are configured or all fetches fail.'
            )->setCallable(fn (): string => $this->sitebuilderFacade->listRemoteContentAssetUrls()),

            Tool::make(
                'search_remote_content_asset_urls',
                'Search remote content asset URLs using a regex pattern. Matches against the full URL (domain, path, and filename). Returns JSON array of matching URLs. Use for precise filtering when you need specific assets. Examples: "hero" matches URLs containing hero, "uploads" matches URLs with uploads in path or domain, "\\.png$" matches PNG files.'
            )->addProperty(
                new ToolProperty(
                    'regex_pattern',
                    PropertyType::STRING,
                    'PCRE regex pattern to match against the full URL (without delimiters). Examples: "hero", "uploads", "\\.jpg$"',
                    true
                )
            )->setCallable(fn (string $regex_pattern): string => $this->sitebuilderFacade->searchRemoteContentAssetUrls($regex_pattern)),

            Tool::make(
                'get_remote_asset_info',
                'Get metadata (width, height, mimeType, sizeInBytes) for a remote image URL without downloading the full file. Returns JSON; on failure returns an object with an "error" key. Use for remote assets from list_remote_content_asset_urls when you need dimensions or format.'
            )->addProperty(
                new ToolProperty(
                    'url',
                    PropertyType::STRING,
                    'The full URL of the remote image (e.g. from list_remote_content_asset_urls)',
                    true
                )
            )->setCallable(fn (string $url): string => $this->sitebuilderFacade->getRemoteAssetInfo($url)),

            Tool::make(
                'fetch_remote_web_page',
                'Fetch textual content from a remote web page via cURL. Use this when the user asks to inspect, summarize, adapt, or copy content from an external URL. Returns JSON with response metadata and page content; on failure returns JSON with an "error" key.'
            )->addProperty(
                new ToolProperty(
                    'url',
                    PropertyType::STRING,
                    'The absolute URL to fetch (http or https).',
                    true
                )
            )->setCallable(fn (string $url): string => $this->sitebuilderFacade->fetchRemoteWebPage($url)),

            Tool::make(
                'get_workspace_rules',
                'Get project-specific rules from .sitebuilder/rules/ folders. Returns a JSON object where keys are rule names (filename without .md extension) and values are the rule contents (Markdown text). IMPORTANT: You must call this tool at least once at the start of every session to understand project-specific conventions and requirements.'
            )->setCallable(fn (): string => $this->sitebuilderFacade->getWorkspaceRules()),
        ]);
    }
}
