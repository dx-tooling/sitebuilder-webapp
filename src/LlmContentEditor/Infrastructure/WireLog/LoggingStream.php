<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\WireLog;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

/**
 * A stream decorator that buffers SSE lines and logs each complete
 * line to the llm_wire logger as it is read.
 *
 * Because NeuronAI reads the OpenAI streaming response byte-by-byte,
 * we buffer until we see a newline and then emit the full SSE line.
 */
final class LoggingStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private string $lineBuffer = '';

    public function __construct(
        private readonly StreamInterface $stream,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function read(int $length): string
    {
        $data = $this->stream->read($length);

        if ($data === '') {
            return $data;
        }

        $this->lineBuffer .= $data;

        // Flush complete lines (SSE events are newline-delimited)
        while (($pos = strpos($this->lineBuffer, "\n")) !== false) {
            $line             = substr($this->lineBuffer, 0, $pos);
            $this->lineBuffer = substr($this->lineBuffer, $pos + 1);

            $trimmed = trim($line);
            if ($trimmed !== '') {
                $this->logger->debug('← chunk', ['line' => $trimmed]);
            }
        }

        return $data;
    }

    public function eof(): bool
    {
        return $this->stream->eof();
    }

    /**
     * Flush any remaining buffer content on close.
     */
    public function close(): void
    {
        $remaining = trim($this->lineBuffer);
        if ($remaining !== '') {
            $this->logger->debug('← chunk', ['line' => $remaining]);
        }
        $this->lineBuffer = '';

        $this->stream->close();
    }

    public function getContents(): string
    {
        $contents = $this->stream->getContents();

        if ($contents !== '') {
            $this->logger->debug('← body', ['body' => $contents]);
        }

        return $contents;
    }
}
