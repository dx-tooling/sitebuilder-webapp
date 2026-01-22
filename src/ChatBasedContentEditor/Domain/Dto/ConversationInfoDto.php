<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Domain\Dto;

use App\ChatBasedContentEditor\Domain\Enum\ConversationStatus;
use DateTimeImmutable;

/**
 * Internal DTO for conversation information within ChatBasedContentEditor vertical.
 * Not exposed via facade - used for internal service communication.
 */
final readonly class ConversationInfoDto
{
    public function __construct(
        public string             $id,
        public string             $workspaceId,
        public string             $userId,
        public ConversationStatus $status,
        public string             $workspacePath,
        public DateTimeImmutable  $createdAt,
    ) {
    }
}
