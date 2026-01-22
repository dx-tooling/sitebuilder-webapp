<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Infrastructure\Service;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Service for generating conversation URLs.
 */
final readonly class ConversationUrlService implements ConversationUrlServiceInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Generate the URL to view a conversation.
     */
    public function getConversationUrl(string $conversationId): string
    {
        return $this->urlGenerator->generate(
            'chat_based_content_editor.presentation.show',
            ['conversationId' => $conversationId],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }
}
