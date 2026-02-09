<?php

declare(strict_types=1);

namespace App\Tests\Unit\LlmContentEditor\ChatHistory;

use App\LlmContentEditor\Facade\Dto\ConversationMessageDto;
use App\LlmContentEditor\Infrastructure\ChatHistory\MessageSerializer;
use NeuronAI\Chat\Messages\AssistantMessage;
use PHPUnit\Framework\TestCase;

/**
 * @see https://github.com/dx-tooling/sitebuilder-webapp/issues/83
 */
final class MessageSerializerTest extends TestCase
{
    private MessageSerializer $serializer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->serializer = new MessageSerializer();
    }

    /**
     * Turn activity summaries (and legacy note-to-self messages) stored as assistant_note_to_self
     * are deserialized as AssistantMessage with a "[Summary of previous turn actions:]" prefix.
     */
    public function testFromDtoWithAssistantNoteToSelfReturnsAssistantMessageWithPrefixedContent(): void
    {
        $dto = new ConversationMessageDto(
            ConversationMessageDto::ROLE_ASSISTANT_NOTE_TO_SELF,
            '{"content":"1. [list_directory] path=\"/workspace\" → src/, dist/"}'
        );

        $message = $this->serializer->fromDto($dto);

        self::assertInstanceOf(AssistantMessage::class, $message);
        $content = $message->getContent();
        self::assertIsString($content);
        self::assertStringStartsWith('[Summary of previous turn actions:] ', $content);
        self::assertStringContainsString('list_directory', $content);
    }

    public function testFromDtoWithAssistantNoteToSelfAndEmptyContent(): void
    {
        $dto = new ConversationMessageDto(ConversationMessageDto::ROLE_ASSISTANT_NOTE_TO_SELF, '{"content":""}');

        $message = $this->serializer->fromDto($dto);

        self::assertInstanceOf(AssistantMessage::class, $message);
        self::assertSame('[Summary of previous turn actions:] ', $message->getContent());
    }
}
