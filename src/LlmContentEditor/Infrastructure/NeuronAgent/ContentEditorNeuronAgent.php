<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\NeuronAgent;

use App\WorkspaceTooling\Facade\WorkspaceToolingFacadeInterface;
use NeuronAI\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\SystemPrompt;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class ContentEditorNeuronAgent extends Agent
{
    public function __construct(
        private readonly WorkspaceToolingFacadeInterface $fileEditingFacade
    ) {
    }

    protected function provider(): AIProviderInterface
    {
        /** @var string $apiKey */
        $apiKey = $_ENV['LLM_CONTENT_EDITOR_OPENAI_API_KEY'];

        return new OpenAI(
            $apiKey,
            'gpt-4.1',
        );
    }

    public function instructions(): string
    {
        return (string) new SystemPrompt(
            [
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
            ],
            [
                '1. EXPLORE: List the workspace root folder to understand its structure.',
                '2. UNDERSTAND: Read package.json and README.md to learn about the project.',
                '3. INVESTIGATE: Browse src/ and other relevant folders to find existing patterns.',
                '4. PLAN: Understand what files need to be created or modified.',
                '5. EDIT: Use apply_diff_to_file with unified diff format for precise changes.',
                '6. VERIFY: Run run_quality_checks to ensure code standards are met.',
                '7. TEST: Run run_tests to verify functionality.',
                '8. BUILD: Run run_build to confirm the project compiles successfully.',
            ],
            [
                'Summarize what changes were made and why.',
                'If quality checks, tests, or build fail, analyze the errors and fix them.',
                'Always verify your changes with quality checks, tests, and build before finishing.',
            ],
        );
    }

    /**
     * @phpstan-ignore noAssociativeArraysAcrossBoundaries.return
     */
    protected function tools(): array
    {
        return [
            Tool::make(
                'get_folder_content',
                'List the files and directories in a folder. Returns a newline-separated list of file names.',
            )->addProperty(
                new ToolProperty(
                    'path_to_folder',
                    PropertyType::STRING,
                    'The absolute path to the folder whose contents shall be listed.',
                    true
                )
            )->setCallable(fn (string $path_to_folder): string => $this->fileEditingFacade->getFolderContent($path_to_folder)),

            Tool::make(
                'get_file_content',
                'Read and return the full content of a file.',
            )->addProperty(
                new ToolProperty(
                    'path_to_file',
                    PropertyType::STRING,
                    'The absolute path to the file to read.',
                    true
                )
            )->setCallable(fn (string $path_to_file): string => $this->fileEditingFacade->getFileContent($path_to_file)),

            Tool::make(
                'apply_diff_to_file',
                'Apply a unified diff (v4a format) to modify a file. The diff should use the standard unified diff format with @@ line markers, context lines (space prefix), removed lines (- prefix), and added lines (+ prefix). Example: @@ -1,3 +1,4 @@\n line1\n line2\n+new line\n line3',
            )->addProperty(
                new ToolProperty(
                    'path_to_file',
                    PropertyType::STRING,
                    'The absolute path to the file to modify.',
                    true
                )
            )->addProperty(
                new ToolProperty(
                    'diff',
                    PropertyType::STRING,
                    'The unified diff to apply. Use @@ -start,count +start,count @@ header, space-prefixed context lines, minus-prefixed lines to remove, and plus-prefixed lines to add.',
                    true
                )
            )->setCallable(fn (string $path_to_file, string $diff): string => $this->fileEditingFacade->applyV4aDiffToFile($path_to_file, $diff)),

            Tool::make(
                'run_quality_checks',
                'Run quality checks (npm run quality) in the workspace to verify code standards and linting. Returns the command output.',
            )->addProperty(
                new ToolProperty(
                    'path_to_folder',
                    PropertyType::STRING,
                    'The absolute path to the workspace folder where quality checks should run.',
                    true
                )
            )->setCallable(fn (string $path_to_folder): string => $this->fileEditingFacade->runQualityChecks($path_to_folder)),

            Tool::make(
                'run_tests',
                'Run the test suite (npm run test) in the workspace to verify functionality. Returns the test output.',
            )->addProperty(
                new ToolProperty(
                    'path_to_folder',
                    PropertyType::STRING,
                    'The absolute path to the workspace folder where tests should run.',
                    true
                )
            )->setCallable(fn (string $path_to_folder): string => $this->fileEditingFacade->runTests($path_to_folder)),

            Tool::make(
                'run_build',
                'Build the workspace (npm run build) from source. Returns the build output.',
            )->addProperty(
                new ToolProperty(
                    'path_to_folder',
                    PropertyType::STRING,
                    'The absolute path to the workspace folder to build.',
                    true
                )
            )->setCallable(fn (string $path_to_folder): string => $this->fileEditingFacade->runBuild($path_to_folder)),
        ];
    }
}
