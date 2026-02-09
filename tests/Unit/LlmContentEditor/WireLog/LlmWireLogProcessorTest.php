<?php

declare(strict_types=1);

namespace App\Tests\Unit\LlmContentEditor\WireLog;

use App\LlmContentEditor\Infrastructure\WireLog\LlmWireLogProcessor;
use App\WorkspaceTooling\Facade\AgentExecutionContextInterface;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class LlmWireLogProcessorTest extends TestCase
{
    public function testAddsConversationIdToExtra(): void
    {
        $context = $this->createMock(AgentExecutionContextInterface::class);
        $context->method('getConversationId')->willReturn('conv-123');
        $context->method('getWorkspaceId')->willReturn(null);

        $processor = new LlmWireLogProcessor($context);
        $record    = $processor($this->createLogRecord());

        self::assertSame('conv-123', $record->extra['conversationId']);
        self::assertArrayNotHasKey('workspaceId', $record->extra);
    }

    public function testAddsWorkspaceIdToExtra(): void
    {
        $context = $this->createMock(AgentExecutionContextInterface::class);
        $context->method('getConversationId')->willReturn(null);
        $context->method('getWorkspaceId')->willReturn('ws-456');

        $processor = new LlmWireLogProcessor($context);
        $record    = $processor($this->createLogRecord());

        self::assertArrayNotHasKey('conversationId', $record->extra);
        self::assertSame('ws-456', $record->extra['workspaceId']);
    }

    public function testAddsBothIdsWhenBothPresent(): void
    {
        $context = $this->createMock(AgentExecutionContextInterface::class);
        $context->method('getConversationId')->willReturn('conv-123');
        $context->method('getWorkspaceId')->willReturn('ws-456');

        $processor = new LlmWireLogProcessor($context);
        $record    = $processor($this->createLogRecord());

        self::assertSame('conv-123', $record->extra['conversationId']);
        self::assertSame('ws-456', $record->extra['workspaceId']);
    }

    public function testAddsNothingWhenContextIsEmpty(): void
    {
        $context = $this->createMock(AgentExecutionContextInterface::class);
        $context->method('getConversationId')->willReturn(null);
        $context->method('getWorkspaceId')->willReturn(null);

        $processor = new LlmWireLogProcessor($context);
        $record    = $processor($this->createLogRecord());

        self::assertArrayNotHasKey('conversationId', $record->extra);
        self::assertArrayNotHasKey('workspaceId', $record->extra);
    }

    public function testPreservesExistingExtraFields(): void
    {
        $context = $this->createMock(AgentExecutionContextInterface::class);
        $context->method('getConversationId')->willReturn('conv-123');
        $context->method('getWorkspaceId')->willReturn(null);

        $processor = new LlmWireLogProcessor($context);
        $record    = $processor($this->createLogRecord(['existing' => 'value']));

        self::assertSame('value', $record->extra['existing']);
        self::assertSame('conv-123', $record->extra['conversationId']);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function createLogRecord(array $extra = []): LogRecord
    {
        return new LogRecord(
            DateAndTimeService::getDateTimeImmutable(),
            'llm_wire',
            Level::Debug,
            'test message',
            [],
            $extra,
        );
    }
}
