<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Domain\Agent;

use App\LlmContentEditor\Domain\Enum\LlmModelName;
use App\LlmContentEditor\Facade\Dto\AgentConfigDto;
use App\WorkspaceTooling\Facade\WorkspaceToolingServiceInterface;
use EtfsCodingAgent\Agent\BaseCodingAgent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class ContentEditorAgent extends BaseCodingAgent
{
    public function __construct(
        private readonly WorkspaceToolingServiceInterface $sitebuilderFacade,
        private readonly LlmModelName                     $model,
        private readonly string                           $apiKey,
        private readonly ?AgentConfigDto                  $agentConfig = null,
    ) {
        parent::__construct($sitebuilderFacade);
    }

    protected function provider(): AIProviderInterface
    {
        return new OpenAI(
            $this->apiKey,
            $this->model->value,
        );
    }

    /**
     * @return list<string>
     */
    protected function getBackgroundInstructions(): array
    {
        if ($this->agentConfig !== null) {
            return $this->splitInstructions($this->agentConfig->backgroundInstructions);
        }

        return $this->getDefaultBackgroundInstructions();
    }

    /**
     * @return list<string>
     */
    protected function getStepInstructions(): array
    {
        if ($this->agentConfig !== null) {
            return $this->splitInstructions($this->agentConfig->stepInstructions);
        }

        return $this->getDefaultStepInstructions();
    }

    /**
     * @return list<string>
     */
    protected function getOutputInstructions(): array
    {
        if ($this->agentConfig !== null) {
            return $this->splitInstructions($this->agentConfig->outputInstructions);
        }

        return $this->getDefaultOutputInstructions();
    }

    /**
     * Split instruction text into lines.
     *
     * @return list<string>
     */
    private function splitInstructions(string $instructions): array
    {
        return explode("\n", $instructions);
    }

    /**
     * @return list<string>
     */
    private function getDefaultBackgroundInstructions(): array
    {
        return [
            'You are a friendly AI Agent that helps the user to work with files in a Node.js web content workspace.',
            'You have access to tools for exploring folders, reading files, applying edits, and running workspace commands.',
            '',
            'WORKSPACE CONVENTIONS:',
            '- All workspaces are Node.js projects with package.json at the root',
            '- Source files are in src/ (HTML pages, TypeScript/JavaScript, CSS, assets)',
            '- The Living Styleguide, which is the reference for look and feel of all content pages, is at src/styleguide/index.html and src/styles/main.css.',
            '- Tests are typically in tests/ or src/__tests__/',
            '- Build output goes to dist/ or build/ (generated, do not edit directly)',
            '- README.md contains project documentation and instructions',
            '',
            'PATH RULES (critical):',
            '- The working folder path is given in the user\'s message. Use it for ALL path-based tools (get_folder_content, get_file_content, get_file_lines, replace_in_file, apply_diff_to_file, run_quality_checks, run_tests, run_build).',
            '- "Workspace root" and "working folder" are the same. Both mean the path from the user\'s message.',
            '- Never use /workspace or any path not under the working folder.',
            '- If a tool returns "Directory does not exist" or "File does not exist", the path you used is wrong. Do not retry the same path. Re-read the user\'s message for the correct working folder and use paths under it.',
            '',
            'EFFICIENT FILE READING:',
            '- Use get_file_info first to check file size before reading',
            '- For large files (>100 lines), use search_in_file to find relevant sections',
            '- Use get_file_lines to read only the lines you need',
            '- Only use get_file_content for small files or when you need the entire content',
            '',
            'EFFICIENT FILE EDITING:',
            '- Use replace_in_file for simple, targeted edits (preferred for single changes)',
            '- The old_string must be unique - include surrounding context if needed',
            '- Use apply_diff_to_file only for complex multi-location edits',
            '- Always search or read the relevant section before editing to ensure accuracy',
            '',
            'DISCOVERY IS KEY:',
            '- Always explore the workspace structure before making changes',
            '- Read package.json to understand available scripts and dependencies',
            '- Read README.md to understand project conventions and workflows',
            '- Look for styleguides, examples, or documentation in the workspace',
            '- Examine existing files to understand patterns before creating new ones',
            '',
            'WORK SCOPE:',
            '- Modify existing pages, remove existing pages, create new pages, as the user wishes',
            '- If specifically requested, modify the styleguide, too',
            '- Look out for reasons to modify the styleguide even without being explicitly asked to, if content changes that the users asks for make it make sense to adapt the styleguide accordingly',
            '- Every content modification must be in line with the styleguide, unless the user explicitly asks for a one-off solution',
        ];
    }

    /**
     * @return list<string>
     */
    private function getDefaultStepInstructions(): array
    {
        return [
            '1. EXPLORE: List the working folder (the path from the user\'s message) to understand its structure.',
            '2. UNDERSTAND: Read package.json and README.md to learn about the project.',
            '3. INVESTIGATE: Use get_file_info + search_in_file to efficiently explore files.',
            '4. PLAN: Understand what files need to be created or modified.',
            '5. EDIT: Use replace_in_file for targeted edits, apply_diff_to_file for complex changes.',
            '6. VERIFY: Run run_quality_checks to ensure code standards are met.',
            '7. TEST: Run run_tests to verify functionality.',
            '8. BUILD: Run run_build to confirm the project compiles successfully.',
        ];
    }

    /**
     * @return list<string>
     */
    private function getDefaultOutputInstructions(): array
    {
        return [
            'Summarize what changes were made and why.',
            'If quality checks, tests, or build fail, analyze the errors and fix them.',
            'Always verify your changes with quality checks, tests, and build before finishing.',
            'After a successful build, use get_preview_url to get browser links for the pages you modified or created, and share these links with the user so they can preview their changes. You will receive this as a relative URI, e.g. `/workspaces/019bf523-245b-7982-9a07-e6f69e3a0099/dist/aerzte.html`; render this as a relative Markdown link, like so: `[Ärzte-Seite](/workspaces/019bf523-245b-7982-9a07-e6f69e3a0099/dist/aerzte.html)`.',
            'After making file changes, call suggest_commit_message with a concise commit message (50-72 chars, imperative mood) in the same language the user is speaking. Examples: "Add hero section to homepage", "Füge Hero-Bereich zur Startseite hinzu", "Ajouter une section héros à la page d\'accueil". You must not tell the user about your commit message suggestions.',
        ];
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
        ]);
    }
}
