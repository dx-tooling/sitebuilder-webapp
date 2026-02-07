<?php

declare(strict_types=1);

namespace App\Tests\Unit\LlmContentEditor\WireLog;

use App\LlmContentEditor\Infrastructure\WireLog\LoggingStream;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use function array_key_exists;
use function is_string;
use function str_starts_with;

final class LoggingStreamTest extends TestCase
{
    public function testLogsCompleteSSELinesOnRead(): void
    {
        $inner  = Utils::streamFor("data: {\"content\":\"hello\"}\ndata: {\"content\":\"world\"}\n");
        $logger = $this->createLoggerMock();

        $logger->expects(self::exactly(2))
            ->method('debug')
            ->with(
                '← chunk',
                self::callback(static function (array $context): bool {
                    return array_key_exists('line', $context)
                        && is_string($context['line'])
                        && str_starts_with($context['line'], 'data:');
                })
            );

        $stream = new LoggingStream($inner, $logger);

        // Read the entire stream
        while (!$stream->eof()) {
            $stream->read(8192);
        }
    }

    public function testBuffersPartialLinesAcrossReads(): void
    {
        $inner  = Utils::streamFor('data: partial');
        $logger = $this->createLoggerMock();

        // No newline encountered during reads, so no debug calls from read()
        $logger->expects(self::once())
            ->method('debug')
            ->with('← chunk', ['line' => 'data: partial']);

        $stream = new LoggingStream($inner, $logger);
        $stream->read(5);  // "data:"
        $stream->read(8);  // " partial"

        // Closing flushes the remaining buffer
        $stream->close();
    }

    public function testSkipsEmptyLines(): void
    {
        $inner  = Utils::streamFor("\n\n\ndata: hello\n\n");
        $logger = $this->createLoggerMock();

        // Only the non-empty line should be logged
        $logger->expects(self::once())
            ->method('debug')
            ->with('← chunk', ['line' => 'data: hello']);

        $stream = new LoggingStream($inner, $logger);
        while (!$stream->eof()) {
            $stream->read(8192);
        }
    }

    public function testReturnsOriginalDataUnmodified(): void
    {
        $content = "data: {\"id\":\"123\"}\n";
        $inner   = Utils::streamFor($content);
        $logger  = $this->createLoggerMock();
        $stream  = new LoggingStream($inner, $logger);

        $result = '';
        while (!$stream->eof()) {
            $result .= $stream->read(8192);
        }

        self::assertSame($content, $result);
    }

    public function testGetContentsLogsFull(): void
    {
        $content = '{"result":"ok"}';
        $inner   = Utils::streamFor($content);
        $logger  = $this->createLoggerMock();

        $logger->expects(self::once())
            ->method('debug')
            ->with('← body', ['body' => $content]);

        $stream = new LoggingStream($inner, $logger);
        $result = $stream->getContents();

        self::assertSame($content, $result);
    }

    public function testGetContentsDoesNotLogEmptyBody(): void
    {
        $inner  = Utils::streamFor('');
        $logger = $this->createLoggerMock();

        $logger->expects(self::never())->method('debug');

        $stream = new LoggingStream($inner, $logger);
        $stream->getContents();
    }

    public function testCloseDoesNotLogWhenBufferEmpty(): void
    {
        $inner  = Utils::streamFor("data: line\n");
        $logger = $this->createLoggerMock();

        // One call for the line during read, none for close (buffer empty after newline)
        $logger->expects(self::once())->method('debug');

        $stream = new LoggingStream($inner, $logger);
        $stream->read(8192);
        $stream->close();
    }

    public function testReadReturnsEmptyStringWithoutLogging(): void
    {
        $inner  = Utils::streamFor('');
        $logger = $this->createLoggerMock();

        $logger->expects(self::never())->method('debug');

        $stream = new LoggingStream($inner, $logger);
        $result = $stream->read(8192);

        self::assertSame('', $result);
    }

    private function createLoggerMock(): LoggerInterface&MockObject
    {
        return $this->createMock(LoggerInterface::class);
    }
}
