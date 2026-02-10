<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Infrastructure\Adapter;

use GuzzleHttp\HandlerStack;
use NeuronAI\Chat\Messages\UserMessage;
use RuntimeException;

use function count;
use function sprintf;

/**
 * Generates image prompts using a NeuronAI agent with the deliver_image_prompt tool.
 */
class OpenAiPromptGenerator implements PromptGeneratorInterface
{
    public function __construct(
        private readonly ?HandlerStack $guzzleHandlerStack = null,
    ) {
    }

    /**
     * @return list<array{prompt: string, fileName: string}>
     */
    public function generatePrompts(
        string $pageHtml,
        string $userPrompt,
        string $apiKey,
        int $count,
    ): array {
        $agent = new ImagePromptAgent(
            $apiKey,
            $pageHtml,
            $count,
            'gpt-5.2',
            $this->guzzleHandlerStack,
        );

        $agent->chat(new UserMessage($userPrompt));

        $prompts = $agent->getCollectedPrompts();

        if (count($prompts) < $count) {
            throw new RuntimeException(sprintf(
                'Expected %d image prompts but the agent delivered only %d.',
                $count,
                count($prompts),
            ));
        }

        // Return only the expected count (in case the agent delivered more)
        return array_slice($prompts, 0, $count);
    }
}
