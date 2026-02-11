<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Infrastructure\Adapter;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Tools\ToolInterface;
use Psr\Http\Message\ResponseInterface;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_values;
use function in_array;
use function is_string;
use function json_encode;
use function trim;

/**
 * Patched Gemini provider that fixes function-call detection for Gemini 3 models.
 *
 * NeuronAI's built-in HandleChat trait only checks parts[0] for a functionCall key.
 * Gemini 3 models may return text or thought parts before functionCall parts,
 * causing the tool calls to be silently ignored. This override scans ALL parts.
 *
 * @see https://ai.google.dev/gemini-api/docs/thought-signatures
 */
class PatchedGemini extends Gemini
{
    /**
     * Finish reasons that indicate a blocked response (potentially retryable).
     *
     * @var list<string>
     */
    private static array $blockedFinishReasons = [
        'SAFETY',
        'BLOCKLIST',
        'OTHER',
        'RECITATION',
    ];

    /**
     * @param list<Message> $messages
     */
    public function chat(array $messages): Message
    {
        /** @var Message $message */
        $message = $this->patchedChatAsync($messages)->wait();

        return $message;
    }

    /**
     * Overrides the HandleChat trait to scan ALL parts for functionCall,
     * not just parts[0].
     *
     * @param list<Message> $messages
     */
    public function chatAsync(array $messages): PromiseInterface
    {
        return $this->patchedChatAsync($messages);
    }

    /**
     * @param list<Message> $messages
     */
    private function patchedChatAsync(array $messages): PromiseInterface
    {
        $json = [
            'contents' => $this->messageMapper()->map($messages),
            ...$this->parameters,
        ];

        if ($this->system !== null) {
            $json['system_instruction'] = [
                'parts' => [
                    ['text' => $this->system],
                ],
            ];
        }

        if ($this->tools !== []) {
            $json['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        return $this->client->postAsync(
            trim($this->baseUri, '/') . "/{$this->model}:generateContent",
            [RequestOptions::JSON => $json],
        )
            ->then(function (ResponseInterface $response): Message {
                return $this->parseGeminiResponse($response);
            });
    }

    /**
     * Overrides parent to reindex the tools array after filtering.
     *
     * The original uses array_filter() which preserves array keys, causing
     * gaps when text/thought parts precede functionCall parts.
     *
     * @param array<string, mixed> $message
     */
    protected function createToolCallMessage(array $message): Message // @phpstan-ignore noAssociativeArraysAcrossBoundaries.param
    {
        $signature = null;

        /** @var list<array<string, mixed>> $messageParts */
        $messageParts = $message['parts'];

        $tools = array_map(function (array $item) use (&$signature): ?ToolInterface {
            if (!array_key_exists('functionCall', $item)) {
                return null;
            }

            /** @var string|false $sig */
            $sig = $item['thoughtSignature'] ?? false;
            if ($sig !== false) {
                $signature = $sig;
            }

            /** @var array{name: string, args: array<string, mixed>} $functionCall */
            $functionCall = $item['functionCall'];

            return $this->findTool($functionCall['name'])
                ->setInputs($functionCall['args'])
                ->setCallId($functionCall['name']);
        }, $messageParts);

        /** @var string|null $messageContent */
        $messageContent = $message['content'] ?? null;

        // array_values() reindexes the array so tools start at index 0
        $result = new ToolCallMessage(
            $messageContent,
            array_values(array_filter($tools)),
        );

        if ($signature !== null) {
            /** @var string $signatureValue */
            $signatureValue = $signature;
            $result->addMetadata('thoughtSignature', $signatureValue);
        }

        return $result;
    }

    private function parseGeminiResponse(ResponseInterface $response): Message
    {
        /** @var array<string, mixed>|null $result */
        $result = json_decode($response->getBody()->getContents(), true);

        if ($result === null || empty($result['candidates'])) {
            throw new ProviderException(
                'Gemini API returned no candidates. Response: ' . json_encode($result)
            );
        }

        /** @var list<array<string, mixed>> $candidates */
        $candidates   = $result['candidates'];
        $candidate    = $candidates[0];
        $finishReason = is_string($candidate['finishReason'] ?? null)
            ? $candidate['finishReason']
            : 'UNKNOWN';

        /** @var array<string, mixed> $content */
        $content = $candidate['content'] ?? [];

        if (empty($content['parts'])) {
            if (in_array($finishReason, self::$blockedFinishReasons, true)) {
                throw new ProviderException(
                    "Gemini response blocked (finishReason: {$finishReason}). "
                    . 'This may be transient - retry recommended.'
                );
            }

            return new AssistantMessage('');
        }

        /** @var list<array<string, mixed>> $parts */
        $parts = $content['parts'];

        // FIX: Scan ALL parts for functionCall, not just parts[0].
        // Gemini 3 models may return text/thought parts before functionCall parts.
        $hasFunctionCall = false;
        foreach ($parts as $part) {
            if (array_key_exists('functionCall', $part) && $part['functionCall'] !== []) {
                $hasFunctionCall = true;

                break;
            }
        }

        if ($hasFunctionCall) {
            $parsedResponse = $this->createToolCallMessage($content);
        } else {
            /** @var string $textContent */
            $textContent    = $parts[0]['text'] ?? '';
            $parsedResponse = new AssistantMessage($textContent);
        }

        if (array_key_exists('groundingMetadata', $candidate)) {
            /** @var string $groundingMetadata */
            $groundingMetadata = $candidate['groundingMetadata'];
            $parsedResponse->addMetadata('groundingMetadata', $groundingMetadata);
        }

        if (array_key_exists('usageMetadata', $result)) {
            /** @var array{promptTokenCount: int, candidatesTokenCount?: int} $usageMeta */
            $usageMeta = $result['usageMetadata'];
            $parsedResponse->setUsage(
                new Usage(
                    $usageMeta['promptTokenCount'],
                    $usageMeta['candidatesTokenCount'] ?? 0,
                )
            );
        }

        return $parsedResponse;
    }
}
