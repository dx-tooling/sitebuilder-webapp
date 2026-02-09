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

    public function testFromDtoWithAssistantNoteToSelfReturnsAssistantMessageWithPrefixedContent(): void
    {
        $dto = new ConversationMessageDto(
            ConversationMessageDto::ROLE_ASSISTANT_NOTE_TO_SELF,
            '{"content":"I added the footer. User may ask for styling next."}'
        );

        $message = $this->serializer->fromDto($dto);

        self::assertInstanceOf(AssistantMessage::class, $message);
        $content = $message->getContent();
        self::assertIsString($content);
        self::assertStringStartsWith('[Note to self from previous turn:] ', $content);
        self::assertStringContainsString('I added the footer. User may ask for styling next.', $content);
    }

    public function testFromDtoWithAssistantNoteToSelfAndEmptyContent(): void
    {
        $dto = new ConversationMessageDto(ConversationMessageDto::ROLE_ASSISTANT_NOTE_TO_SELF, '{"content":""}');

        $message = $this->serializer->fromDto($dto);

        self::assertInstanceOf(AssistantMessage::class, $message);
        self::assertSame('[Note to self from previous turn:] ', $message->getContent());
    }
}
