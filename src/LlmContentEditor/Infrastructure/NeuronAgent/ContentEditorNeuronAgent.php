<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\NeuronAgent;

use NeuronAI\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\SystemPrompt;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class ContentEditorNeuronAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return new OpenAI(
            key: 'YOUR_OPENAI_API_KEY_HERE',
            model: 'gpt-5.1',
        );
    }

    public function instructions(): string
    {
        return (string) new SystemPrompt(
            background: ['You are a friendly AI Agent that helps the user to edit text files in a folder.'],
        );
    }

    protected function tools(): array
    {
        return [
            Tool::make(
                'get_folder_content',
                'Get the content of a folder on a computer filesystem.',
            )->addProperty(
                new ToolProperty(
                    name: 'path_to_folder',
                    type: PropertyType::STRING,
                    description: 'The folder whose contents shall be returned.',
                    required: true
                )
            )->setCallable(function (string $pathToFolder) {
                return 'Folder content';
            })
        ];
    }
}
