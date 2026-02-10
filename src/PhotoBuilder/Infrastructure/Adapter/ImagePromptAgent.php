<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Infrastructure\Adapter;

use App\PhotoBuilder\Domain\Dto\ImagePromptResultDto;
use GuzzleHttp\HandlerStack;
use NeuronAI\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HttpClientOptions;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

use function sprintf;

/**
 * NeuronAI agent that generates image prompts by calling a deliver_image_prompt tool.
 *
 * The agent receives a page's HTML content in its system prompt and the user's
 * style preferences as the user message. It calls the deliver_image_prompt tool
 * once per image with both the prompt text and a descriptive filename.
 */
class ImagePromptAgent extends Agent
{
    /** @var list<ImagePromptResultDto> */
    private array $collectedPrompts = [];

    public function __construct(
        private readonly string        $apiKey,
        private readonly string        $pageHtml,
        private readonly int           $imageCount,
        private readonly string        $model = 'gpt-5.2',
        private readonly ?HandlerStack $guzzleHandlerStack = null,
    ) {
    }

    protected function provider(): AIProviderInterface
    {
        $httpOptions = null;

        if ($this->guzzleHandlerStack !== null) {
            $httpOptions = new HttpClientOptions(
                null,
                null,
                null,
                $this->guzzleHandlerStack,
            );
        }

        return new OpenAI(
            $this->apiKey,
            $this->model,
            [],
            false,
            $httpOptions,
        );
    }

    public function instructions(): string
    {
        return sprintf(
            'You are a friendly AI assistant that helps the user to generate %d prompts '
            . 'that each will be fed into an LLM-backed AI image generation agent, in order to '
            . 'generate images that shall be used on a web page with the following contents:'
            . "\n\n%s\n\n"
            . 'Think about what each of the %d images should show in order to optimally fit '
            . 'the narrative of the web page content.'
            . "\n\n"
            . 'For each image, call the deliver_image_prompt tool with:'
            . "\n- A detailed, descriptive prompt suitable for an AI image generation model"
            . "\n- A descriptive, kebab-case filename (with .png extension) that clearly describes "
            . 'what the image shows (e.g. "modern-office-team-collaborating.png", not "office.png" '
            . 'or "image1.png")',
            $this->imageCount,
            $this->pageHtml,
            $this->imageCount,
        );
    }

    /**
     * @return list<\NeuronAI\Tools\ToolInterface>
     */
    protected function tools(): array
    {
        return [
            Tool::make(
                'deliver_image_prompt',
                'Deliver a single image generation prompt with a descriptive filename. '
                . 'Call this tool once per image.',
            )
                ->addProperty(
                    new ToolProperty(
                        'prompt',
                        PropertyType::STRING,
                        'A detailed, descriptive prompt for AI image generation.',
                        true
                    )
                )
                ->addProperty(
                    new ToolProperty(
                        'file_name',
                        PropertyType::STRING,
                        'A descriptive, kebab-case filename with .png extension (e.g. "cozy-cafe-winter-scene.png").',
                        true
                    )
                )
                ->setCallable(function (string $prompt, string $file_name): string {
                    $this->collectedPrompts[] = new ImagePromptResultDto($prompt, $file_name);

                    return 'Prompt delivered successfully.';
                }),
        ];
    }

    /**
     * Get all prompts collected via tool calls.
     *
     * @return list<ImagePromptResultDto>
     */
    public function getCollectedPrompts(): array
    {
        return $this->collectedPrompts;
    }

    /**
     * Reset collected prompts (useful for re-runs).
     */
    public function resetCollectedPrompts(): void
    {
        $this->collectedPrompts = [];
    }
}
