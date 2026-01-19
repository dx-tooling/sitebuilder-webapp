<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\NeuronAgent;

use App\LlmFileEditing\Facade\LlmFileEditingFacadeInterface;
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
        private readonly LlmFileEditingFacadeInterface $fileEditingFacade
    ) {
    }

    protected function provider(): AIProviderInterface
    {
        return new OpenAI(
            'YOUR_OPENAI_API_KEY_HERE',
            'gpt-4.1',
        );
    }

    public function instructions(): string
    {
        return (string) new SystemPrompt(
            [
                'You are a friendly AI Agent that helps the user to edit text files in a folder.',
                'You have access to tools for listing folder contents, reading files, and applying edits.',
            ],
            [
                '1. First, use get_folder_content to list the files in the specified folder.',
                '2. Use get_file_content to read the content of files you need to understand or modify.',
                '3. When you need to edit a file, use apply_diff_to_file with a unified diff in v4a format.',
            ],
            [
                'After completing the edit, summarize what changes were made.',
                'If you encounter any errors, explain what went wrong.',
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
        ];
    }
}
