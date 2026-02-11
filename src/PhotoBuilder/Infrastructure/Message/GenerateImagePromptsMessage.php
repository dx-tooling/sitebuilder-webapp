<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Infrastructure\Message;

use EnterpriseToolingForSymfony\SharedBundle\WorkerSystem\SymfonyMessage\ImmediateSymfonyMessageInterface;

/**
 * Dispatched to trigger async generation of image prompts for a photo session.
 *
 * @param list<string> $keepImageIds Image IDs whose prompts must not be regenerated (Keep prompt checked)
 */
final readonly class GenerateImagePromptsMessage implements ImmediateSymfonyMessageInterface
{
    /**
     * @param list<string> $keepImageIds
     */
    public function __construct(
        public string $sessionId,
        public string $locale,
        public array  $keepImageIds = [],
    ) {
    }
}
