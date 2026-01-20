<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Infrastructure\Message;

use EnterpriseToolingForSymfony\SharedBundle\WorkerSystem\SymfonyMessage\ImmediateSymfonyMessageInterface;

readonly class RunEditSessionMessage implements ImmediateSymfonyMessageInterface
{
    public function __construct(
        public string $sessionId
    ) {
    }
}
