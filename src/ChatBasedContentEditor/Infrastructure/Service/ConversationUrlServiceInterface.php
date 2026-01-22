<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Infrastructure\Service;

/**
 * Interface for generating conversation URLs.
 */
interface ConversationUrlServiceInterface
{
    /**
     * Generate the URL to view a conversation.
     */
    public function getConversationUrl(string $conversationId): string;
}
