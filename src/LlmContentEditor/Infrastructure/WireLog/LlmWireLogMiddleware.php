<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\WireLog;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

use function json_decode;

/**
 * Creates a Guzzle HandlerStack with middleware that logs raw LLM provider
 * HTTP traffic (requests + responses, including streaming chunks) to the
 * llm_wire Monolog channel.
 */
final class LlmWireLogMiddleware
{
    /**
     * Build a HandlerStack with the wire-logging middleware pushed on top.
     */
    public static function createHandlerStack(LoggerInterface $logger): HandlerStack
    {
        $stack = HandlerStack::create();
        $stack->push(self::middleware($logger), 'llm_wire_log');

        return $stack;
    }

    /**
     * The actual Guzzle middleware callable.
     *
     * @return callable(callable): callable
     */
    private static function middleware(LoggerInterface $logger): callable
    {
        return static function (callable $handler) use ($logger): callable {
            return static function (RequestInterface $request, array $options) use ($handler, $logger): PromiseInterface {
                self::logRequest($logger, $request);

                /** @var PromiseInterface $promise */
                $promise = $handler($request, $options);

                return $promise->then(
                    static function (ResponseInterface $response) use ($logger, $request, $options): ResponseInterface {
                        $logger->debug('← response', [
                            'status'  => $response->getStatusCode(),
                            'headers' => self::flattenHeaders($response->getHeaders()),
                        ]);

                        // If this was a streaming request, wrap the body to tee chunks to the logger
                        $isStreaming = ($options['stream'] ?? false) === true;
                        if ($isStreaming) {
                            $loggingStream = new LoggingStream($response->getBody(), $logger);

                            return $response->withBody($loggingStream);
                        }

                        return $response;
                    }
                );
            };
        };
    }

    private static function logRequest(LoggerInterface $logger, RequestInterface $request): void
    {
        $bodyString = (string) $request->getBody();

        // Rewind the body so the actual handler can still read it
        $request->getBody()->rewind();

        // Try to decode as JSON for nicer log output
        $bodyData = json_decode($bodyString, true);

        $logger->debug('→ request', [
            'method'  => $request->getMethod(),
            'url'     => (string) $request->getUri(),
            'headers' => self::flattenHeaders($request->getHeaders()),
            'body'    => $bodyData ?? $bodyString,
        ]);
    }

    /**
     * Flatten multi-value headers to simple strings for readable log output.
     *
     * @param array<string, list<string>> $headers
     *
     * @return array<string, string>
     */
    private static function flattenHeaders(array $headers): array
    {
        $flat = [];
        foreach ($headers as $name => $values) {
            $flat[$name] = implode(', ', $values);
        }

        return $flat;
    }
}
