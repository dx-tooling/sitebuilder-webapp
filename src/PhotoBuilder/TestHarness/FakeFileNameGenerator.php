<?php

declare(strict_types=1);

namespace App\PhotoBuilder\TestHarness;

use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\PhotoBuilder\Infrastructure\Adapter\FileNameGeneratorInterface;
use Psr\Log\LoggerInterface;

/**
 * Fake filename generator that returns a deterministic filename.
 *
 * Enable via env var PHOTO_BUILDER_SIMULATE_IMAGE_GENERATION=1.
 */
final readonly class FakeFileNameGenerator implements FileNameGeneratorInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function generateFileName(
        string           $prompt,
        string           $apiKey,
        LlmModelProvider $provider,
    ): string {
        $this->logger->info('PhotoBuilder TestHarness: Returning fake filename (skipping LLM call)');

        return 'fake-generated-image.png';
    }
}
