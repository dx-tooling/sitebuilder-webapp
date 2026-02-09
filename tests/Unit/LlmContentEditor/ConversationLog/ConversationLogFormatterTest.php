<?php

declare(strict_types=1);

namespace App\Tests\Unit\LlmContentEditor\ConversationLog;

use App\LlmContentEditor\Infrastructure\ConversationLog\ConversationLogFormatter;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class ConversationLogFormatterTest extends TestCase
{
    private ConversationLogFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new ConversationLogFormatter();
    }

    public function testFormatsWithConversationId(): void
    {
        $record = $this->createLogRecord('USER → Hello', ['conversationId' => 'conv-abc']);

        $output = $this->formatter->format($record);

        self::assertStringContainsString('[conv-abc]', $output);
        self::assertStringContainsString('USER → Hello', $output);
    }

    public function testFormatsWithDatetime(): void
    {
        $record = $this->createLogRecord('USER → Hello', ['conversationId' => 'conv-abc']);

        $output = $this->formatter->format($record);

        // Should contain a datetime in Y-m-d H:i:s format
        self::assertMatchesRegularExpression('/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $output);
    }

    public function testFallsBackToDashWhenNoConversationId(): void
    {
        $record = $this->createLogRecord('TOOL_CALL read_file');

        $output = $this->formatter->format($record);

        self::assertStringContainsString('[—]', $output);
        self::assertStringContainsString('TOOL_CALL read_file', $output);
    }

    public function testOutputEndsWithNewline(): void
    {
        $record = $this->createLogRecord('test message', ['conversationId' => 'x']);

        $output = $this->formatter->format($record);

        self::assertStringEndsWith("\n", $output);
    }

    public function testDoesNotIncludeChannelOrLevel(): void
    {
        $record = $this->createLogRecord('test', ['conversationId' => 'x']);

        $output = $this->formatter->format($record);

        self::assertStringNotContainsString('llm_conversation', $output);
        self::assertStringNotContainsString('INFO', $output);
        self::assertStringNotContainsString('DEBUG', $output);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function createLogRecord(string $message, array $extra = []): LogRecord
    {
        return new LogRecord(
            DateAndTimeService::getDateTimeImmutable(),
            'llm_conversation',
            Level::Info,
            $message,
            [],
            $extra,
        );
    }
}
