<?php

declare(strict_types=1);

namespace App\Tests\Unit\LlmContentEditor\ConversationLog;

use App\LlmContentEditor\Infrastructure\ConversationLog\LlmConversationLogObserver;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SplSubject;

use function mb_strlen;
use function str_contains;
use function str_starts_with;

final class LlmConversationLogObserverTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private LlmConversationLogObserver $observer;
    private SplSubject&MockObject $subject;

    protected function setUp(): void
    {
        $this->logger   = $this->createMock(LoggerInterface::class);
        $this->observer = new LlmConversationLogObserver($this->logger);
        $this->subject  = $this->createMock(SplSubject::class);
    }

    public function testLogsToolCallingWithInputs(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn('read_file');
        $tool->method('getInputs')->willReturn(['path' => '/workspace/index.html']);

        $this->logger->expects(self::once())
            ->method('info')
            ->with('TOOL_CALL read_file (path=/workspace/index.html)');

        $this->observer->update($this->subject, 'tool-calling', new ToolCalling($tool));
    }

    public function testLogsToolCallingWithMultipleInputs(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn('write_file');
        $tool->method('getInputs')->willReturn(['path' => '/workspace/style.css', 'content' => 'body{}']);

        $this->logger->expects(self::once())
            ->method('info')
            ->with('TOOL_CALL write_file (path=/workspace/style.css, content=body{})');

        $this->observer->update($this->subject, 'tool-calling', new ToolCalling($tool));
    }

    public function testLogsToolCallingWithNoInputs(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn('get_workspace_rules');
        $tool->method('getInputs')->willReturn([]);

        $this->logger->expects(self::once())
            ->method('info')
            ->with('TOOL_CALL get_workspace_rules');

        $this->observer->update($this->subject, 'tool-calling', new ToolCalling($tool));
    }

    public function testTruncatesLongInputValues(): void
    {
        $longValue = str_repeat('a', 200);
        $tool      = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn('write_file');
        $tool->method('getInputs')->willReturn(['content' => $longValue]);

        $this->logger->expects(self::once())
            ->method('info')
            ->with(self::callback(static function (string $message): bool {
                return str_starts_with($message, 'TOOL_CALL write_file (content=')
                    && str_contains($message, "\u{2026}")
                    && mb_strlen($message) < 200;
            }));

        $this->observer->update($this->subject, 'tool-calling', new ToolCalling($tool));
    }

    public function testLogsToolCalledWithResultLength(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn('read_file');
        $tool->method('getResult')->willReturn('file contents here');

        $this->logger->expects(self::once())
            ->method('info')
            ->with('TOOL_RESULT read_file (18 chars)');

        $this->observer->update($this->subject, 'tool-called', new ToolCalled($tool));
    }

    public function testLogsAgentError(): void
    {
        $exception = new RuntimeException('Connection timed out');

        $this->logger->expects(self::once())
            ->method('info')
            ->with('ERROR Connection timed out');

        $this->observer->update($this->subject, 'tool-error', new AgentError($exception));
    }

    public function testIgnoresNullEvent(): void
    {
        $this->logger->expects(self::never())->method('info');

        $this->observer->update($this->subject, null, new ToolCalling($this->createMock(ToolInterface::class)));
    }

    public function testIgnoresNullData(): void
    {
        $this->logger->expects(self::never())->method('info');

        $this->observer->update($this->subject, 'tool-calling', null);
    }

    public function testIgnoresNonObjectData(): void
    {
        $this->logger->expects(self::never())->method('info');

        $this->observer->update($this->subject, 'tool-calling', 'string data');
    }

    public function testIgnoresUnknownEventTypes(): void
    {
        $message = $this->createMock(\NeuronAI\Chat\Messages\Message::class);

        $this->logger->expects(self::never())->method('info');

        $this->observer->update($this->subject, 'inference-start', new InferenceStart($message));
    }
}
