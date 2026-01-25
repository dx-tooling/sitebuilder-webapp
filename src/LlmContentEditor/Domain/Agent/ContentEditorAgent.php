<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Domain\Agent;

use App\LlmContentEditor\Domain\Enum\LlmModelName;
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
    protected function getStepInstructions(): array
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
    protected function getOutputInstructions(): array
    {
        return [
            'Summarize what changes were made and why.',
            'If quality checks, tests, or build fail, analyze the errors and fix them.',
            'Always verify your changes with quality checks, tests, and build before finishing.',
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
        ]);
    }
}
