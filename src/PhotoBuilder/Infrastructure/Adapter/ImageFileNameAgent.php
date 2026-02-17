<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Infrastructure\Adapter;

use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use GuzzleHttp\HandlerStack;
use NeuronAI\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\HttpClientOptions;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * Lightweight NeuronAI agent that generates a descriptive filename from an image prompt.
 *
 * Given an image generation prompt, the agent calls the deliver_file_name tool
 * with a concise, descriptive kebab-case .png filename.
 *
 * Supports both OpenAI and Google Gemini as the LLM provider.
 */
class ImageFileNameAgent extends Agent
{
    private ?string $collectedFileName = null;

    public function __construct(
        private readonly string           $apiKey,
        private readonly LlmModelProvider $llmProvider = LlmModelProvider::OpenAI,
        private readonly ?HandlerStack    $guzzleHandlerStack = null,
    ) {
    }

    protected function provider(): AIProviderInterface
    {
        $model       = $this->llmProvider->imagePromptGenerationModel()->value;
        $httpOptions = null;

        if ($this->guzzleHandlerStack !== null) {
            $httpOptions = new HttpClientOptions(
                null,
                null,
                null,
                $this->guzzleHandlerStack,
            );
        }

        return match ($this->llmProvider) {
            LlmModelProvider::OpenAI => new OpenAI(
                $this->apiKey,
                $model,
                [],
                false,
                $httpOptions,
            ),
            LlmModelProvider::Google => new Gemini(
                $this->apiKey,
                $model,
                [],
                $httpOptions,
            ),
        };
    }

    public function instructions(): string
    {
        return 'You are an assistant that generates descriptive filenames for images. '
            . 'Given an image generation prompt, call the deliver_file_name tool with a '
            . 'concise, descriptive, kebab-case filename (with .png extension) that clearly '
            . 'describes what the image shows.'
            . "\n\n"
            . 'Guidelines:'
            . "\n- Use kebab-case (lowercase words separated by hyphens)"
            . "\n- Keep it concise but descriptive (3-6 words)"
            . "\n- Always end with .png"
            . "\n- Examples: \"modern-office-team-collaborating.png\", \"cozy-cafe-winter-scene.png\", "
            . '"sunset-over-mountain-lake.png"'
            . "\n- Bad examples: \"image1.png\", \"office.png\", \"photo.png\"";
    }

    /**
     * @return list<\NeuronAI\Tools\ToolInterface>
     */
    protected function tools(): array
    {
        return [
            Tool::make(
                'deliver_file_name',
                'Deliver a descriptive filename for the image described by the prompt.',
            )
                ->addProperty(
                    new ToolProperty(
                        'file_name',
                        PropertyType::STRING,
                        'A descriptive, kebab-case filename with .png extension (e.g. "cozy-cafe-winter-scene.png").',
                        true
                    )
                )
                ->setCallable(function (string $file_name): string {
                    $this->collectedFileName = $file_name;

                    return 'Filename delivered successfully.';
                }),
        ];
    }

    public function getCollectedFileName(): ?string
    {
        return $this->collectedFileName;
    }
}
