<?php

declare(strict_types=1);

namespace App\PhotoBuilder\TestHarness;

use App\PhotoBuilder\Domain\Dto\ImagePromptResultDto;
use App\PhotoBuilder\Infrastructure\Adapter\PromptGeneratorInterface;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;
use Exception;
use Psr\Log\LoggerInterface;

use function sprintf;

/**
 * Fake prompt generator that returns placeholder prompts instantly.
 *
 * Enable via env var PHOTO_BUILDER_SIMULATE_IMAGE_PROMPT_GENERATION=1.
 */
final readonly class FakePromptGenerator implements PromptGeneratorInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws Exception
     */
    public function generatePrompts(
        string $pageHtml,
        string $userPrompt,
        string $apiKey,
        int    $count,
    ): array {
        $this->logger->info(sprintf(
            'PhotoBuilder TestHarness: Generating %d fake image prompts (skipping LLM call)',
            $count,
        ));

        $results = [];

        for ($i = 0; $i < $count; ++$i) {
            $results[] = new ImagePromptResultDto(
                sprintf(
                    'A professional, high-quality stock photo suitable for a business website. Placeholder prompt %d of %d. '
                    . DateAndTimeService::getDateTimeImmutable()->format('H:i:s'),
                    $i + 1,
                    $count,
                ),
                sprintf('placeholder-image-%d.png', $i + 1),
            );
        }

        sleep(5); // for realism

        return $results;
    }
}
