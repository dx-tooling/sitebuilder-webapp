<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Infrastructure\Message;

use EnterpriseToolingForSymfony\SharedBundle\WorkerSystem\SymfonyMessage\ImmediateSymfonyMessageInterface;

/**
 * Dispatched to trigger async generation of a single image.
 */
final readonly class GenerateImageMessage implements ImmediateSymfonyMessageInterface
{
    public function __construct(
        public string  $imageId,
        public ?string $imageSize = null,
    ) {
    }
}
