<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Infrastructure\Adapter;

use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\PhotoBuilder\Domain\Dto\ImagePromptResultDto;
use GuzzleHttp\HandlerStack;
use NeuronAI\Chat\Messages\UserMessage;
use RuntimeException;

use function count;
use function sprintf;

/**
 * Generates image prompts using a NeuronAI agent with the deliver_image_prompt tool.
 * Supports both OpenAI and Google Gemini providers.
 */
class OpenAiPromptGenerator implements PromptGeneratorInterface
{
    public function __construct(
        private readonly ?HandlerStack $guzzleHandlerStack = null,
    ) {
    }

    /**
     * @return list<ImagePromptResultDto>
     */
    public function generatePrompts(
        string           $pageHtml,
        string           $userPrompt,
        string           $apiKey,
        int              $count,
        LlmModelProvider $provider = LlmModelProvider::OpenAI,
    ): array {
        $agent = new ImagePromptAgent(
            $apiKey,
            $pageHtml,
            $count,
            $provider,
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
