<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Infrastructure\Adapter;

use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use GuzzleHttp\HandlerStack;
use NeuronAI\Chat\Messages\UserMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Throwable;

/**
 * Generates a descriptive filename for an image using an LLM agent.
 *
 * Falls back to a slug-based filename if the LLM call fails.
 */
class LlmFileNameGenerator implements FileNameGeneratorInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?HandlerStack   $guzzleHandlerStack = null,
    ) {
    }

    public function generateFileName(
        string           $prompt,
        string           $apiKey,
        LlmModelProvider $provider,
    ): string {
        try {
            $agent = new ImageFileNameAgent(
                $apiKey,
                $provider,
                $this->guzzleHandlerStack,
            );

            $agent->chat(new UserMessage($prompt));

            $fileName = $agent->getCollectedFileName();

            if ($fileName !== null && $fileName !== '') {
                return $fileName;
            }

            $this->logger->warning('ImageFileNameAgent returned no filename, falling back to slug', [
                'prompt' => mb_substr($prompt, 0, 100),
            ]);
        } catch (Throwable $e) {
            $this->logger->warning('ImageFileNameAgent failed, falling back to slug', [
                'error'  => $e->getMessage(),
                'prompt' => mb_substr($prompt, 0, 100),
            ]);
        }

        return self::fallbackFileName($prompt);
    }

    /**
     * Slug-based fallback filename when the LLM call fails.
     */
    private static function fallbackFileName(string $prompt): string
    {
        $slugger = new AsciiSlugger();
        $slug    = $slugger->slug($prompt)->lower()->toString();

        if ($slug === '') {
            return 'generated-image.png';
        }

        $maxLength = 80;

        if (mb_strlen($slug) > $maxLength) {
            $truncated  = mb_substr($slug, 0, $maxLength);
            $lastHyphen = strrpos($truncated, '-');

            if ($lastHyphen !== false && $lastHyphen > 0) {
                $truncated = substr($truncated, 0, $lastHyphen);
            }

            $slug = $truncated;
        }

        return $slug . '.png';
    }
}
