<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Infrastructure\Message;

use EnterpriseToolingForSymfony\SharedBundle\WorkerSystem\SymfonyMessage\ImmediateSymfonyMessageInterface;

/**
 * Dispatched to trigger async generation of image prompts for a photo session.
 */
final readonly class GenerateImagePromptsMessage implements ImmediateSymfonyMessageInterface
{
    public function __construct(
        public string $sessionId,
        public string $locale,
    ) {
    }
}
