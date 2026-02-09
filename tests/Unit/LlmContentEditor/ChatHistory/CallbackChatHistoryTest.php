<?php

declare(strict_types=1);

namespace App\Tests\Unit\LlmContentEditor\ChatHistory;

use App\LlmContentEditor\Facade\Dto\ConversationMessageDto;
use App\LlmContentEditor\Infrastructure\ChatHistory\CallbackChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

/**
 * @see https://github.com/dx-tooling/sitebuilder-webapp/issues/75
 */
final class CallbackChatHistoryTest extends TestCase
{
    public function testMessagesUnderContextWindowAreNotTrimmed(): void
    {
        // Use a generous context window so nothing is trimmed
        $history = new CallbackChatHistory([], 100000);

        $user      = new UserMessage('Hello');
        $assistant = new AssistantMessage('Hi there');

        $history->addMessage($user);
        $history->addMessage($assistant);

        $messages = $history->getMessages();

        self::assertCount(2, $messages);
        self::assertSame($user, $messages[0]);
        self::assertSame($assistant, $messages[1]);
    }

    public function testUserMessageIsPreservedWhenTrimmingClearsHistory(): void
    {
        // Use a very small context window to force aggressive trimming.
        // TokenCounter estimates ~1 token per 4 chars + 3 extra per message.
        // A context window of 10 tokens will force almost everything to be trimmed.
        $history = new CallbackChatHistory([], 10);

        $userMessage = new UserMessage('Create a landing page for the hotel industry');
        $history->addMessage($userMessage);

        // Add a large assistant message that will push us way over the context window
        $largeContent = str_repeat('x', 1000);
        $history->addMessage(new AssistantMessage($largeContent));

        // After trimming, the history should still contain the user message
        // rather than being completely empty
        $messages = $history->getMessages();

        self::assertNotEmpty($messages, 'History must not be empty after trimming — at minimum the UserMessage should be preserved');

        $hasUserMessage = false;

        foreach ($messages as $message) {
            if ($message instanceof UserMessage) {
                $hasUserMessage = true;
            }
        }

        self::assertTrue($hasUserMessage, 'The UserMessage must be preserved after aggressive trimming');
    }

    /**
     * Reproduces the exact scenario from issue #75:
     * UserMessage + many ToolCall/ToolResult pairs exceed the context window,
     * parent's ensureStartsWithUser() clears history to [], and without the
     * fix the agent would receive an empty message list.
     */
    public function testReproductionScenarioUserMessageSurvivesLargeToolHistory(): void
    {
        // Small context window to simulate the real overflow scenario.
        // The real context window is 50000 tokens, but with large tool results
        // the token count gets very high. We simulate with a small window.
        $history = new CallbackChatHistory([], 50);

        $userMessage = new UserMessage('Create a new landing page');
        $history->addMessage($userMessage);

        // Simulate tool call/result pairs that accumulate in a single agent turn
        for ($i = 0; $i < 5; ++$i) {
            $tool = Tool::make('get_file_content', 'Read file')
                ->setCallId('call_' . $i)
                ->setResult(str_repeat('file content line ', 100));

            $toolCallMessage = new ToolCallMessage(null, [$tool]);
            $history->addMessage($toolCallMessage);

            $toolResultMessage = new ToolCallResultMessage([$tool]);
            $history->addMessage($toolResultMessage);
        }

        $messages = $history->getMessages();

        self::assertNotEmpty($messages, 'History must not be empty — this would cause the infinite loop from issue #75');

        // The first message should be the UserMessage (or at least it should be present)
        $firstUserMessage = null;

        foreach ($messages as $message) {
            if ($message instanceof UserMessage && !$message instanceof ToolCallResultMessage) {
                $firstUserMessage = $message;

                break;
            }
        }

        self::assertNotNull($firstUserMessage, 'A genuine UserMessage must exist in the history after trimming');
        self::assertSame('Create a new landing page', $firstUserMessage->getContent());
    }

    public function testToolCallResultMessageIsNotTrackedAsUserMessage(): void
    {
        // ToolCallResultMessage extends UserMessage in NeuronAI.
        // Our tracking must NOT treat it as a genuine user instruction.
        $history = new CallbackChatHistory([], 10);

        $tool = Tool::make('get_workspace_rules', 'Get rules')
            ->setCallId('call_abc')
            ->setResult('{"rule": "value"}');

        // Only add tool messages (no genuine UserMessage)
        $toolCallMessage   = new ToolCallMessage(null, [$tool]);
        $toolResultMessage = new ToolCallResultMessage([$tool]);

        $history->addMessage($toolCallMessage);
        $history->addMessage($toolResultMessage);

        // With no genuine UserMessage tracked, the history may be empty
        // after trimming (this is acceptable — the fix only restores
        // genuine UserMessages, not ToolCallResultMessages)
        $messages = $history->getMessages();

        foreach ($messages as $message) {
            // If any message remains, it should not be a fabricated UserMessage
            self::assertNotInstanceOf(
                ToolCallResultMessage::class,
                $message,
                'ToolCallResultMessage should not be used as the restored UserMessage'
            );
        }
    }

    public function testCallbackIsStillInvokedAfterTrimRecovery(): void
    {
        $history = new CallbackChatHistory([], 10);

        /** @var list<Message> $receivedMessages */
        $receivedMessages = [];
        $history->setOnNewMessageCallback(function (Message $message) use (&$receivedMessages): void {
            $receivedMessages[] = $message;
        });

        $userMessage = new UserMessage('Do something');
        $history->addMessage($userMessage);

        // Force trimming with a large message
        $history->addMessage(new AssistantMessage(str_repeat('x', 1000)));

        // The callback should have been invoked for both messages
        self::assertCount(2, $receivedMessages);
        self::assertSame($userMessage, $receivedMessages[0]);
    }

    public function testLatestUserMessageIsTrackedAcrossMultipleMessages(): void
    {
        $history = new CallbackChatHistory([], 30);

        $firstUser = new UserMessage('First instruction');
        $history->addMessage($firstUser);

        $history->addMessage(new AssistantMessage('Response 1'));

        $secondUser = new UserMessage('Second instruction');
        $history->addMessage($secondUser);

        // Force aggressive trim with a very large message
        $history->addMessage(new AssistantMessage(str_repeat('y', 2000)));

        $messages = $history->getMessages();

        // History should not be empty; the latest UserMessage should be preserved
        self::assertNotEmpty($messages);

        $foundUserContent = null;

        foreach ($messages as $message) {
            if ($message instanceof UserMessage && !$message instanceof ToolCallResultMessage) {
                $foundUserContent = $message->getContent();
            }
        }

        // The latest user message should be the one that was preserved
        self::assertSame('Second instruction', $foundUserContent);
    }

    /**
     * When the persistence callback receives a ToolCallMessage with write_note_to_self,
     * it should enqueue an assistant_note_to_self DTO (facade behavior for note-to-self).
     *
     * @see https://github.com/dx-tooling/sitebuilder-webapp/issues/83
     */
    public function testCallbackReceivesToolCallMessageWithWriteNoteToSelf(): void
    {
        $history = new CallbackChatHistory([]);

        /** @var list<ConversationMessageDto> $enqueued */
        $enqueued = [];
        $history->setOnNewMessageCallback(function (Message $message) use (&$enqueued): void {
            if (!$message instanceof ToolCallMessage) {
                return;
            }
            foreach ($message->getTools() as $tool) {
                if ($tool instanceof Tool) {
                    $dto = ConversationMessageDto::fromWriteNoteToSelfTool($tool);
                    if ($dto !== null) {
                        $enqueued[] = $dto;
                    }
                }
            }
        });

        $tool = Tool::make(ConversationMessageDto::TOOL_NAME_WRITE_NOTE_TO_SELF, 'Note to self')
            ->setInputs(['note' => 'Added footer; user may ask for styling next.']);
        $history->addMessage(new ToolCallMessage(null, [$tool]));

        self::assertCount(1, $enqueued);
        self::assertSame(ConversationMessageDto::ROLE_ASSISTANT_NOTE_TO_SELF, $enqueued[0]->role);
        self::assertStringContainsString('Added footer; user may ask for styling next.', $enqueued[0]->contentJson);
    }
}
