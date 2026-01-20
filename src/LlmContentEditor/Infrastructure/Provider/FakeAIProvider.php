<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\Provider;

use Generator;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\OpenAI\MessageMapper as OpenAIMessageMapper;
use NeuronAI\Providers\ToolPayloadMapperInterface;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use RuntimeException;
use Throwable;

use function is_string;
use function mb_str_split;

/**
 * Fake AI provider for testing that allows deterministic, seedable behavior.
 * When tool calls are triggered, they execute REAL tools via the executeToolsCallback.
 */
final class FakeAIProvider implements AIProviderInterface
{
    /** @var array<string, string|AssistantMessage> */
    private array $responseRules = [];

    /** @var array<string, array{tool: string, inputs: array<string, mixed>}> */
    private array $toolCallRules = [];

    /** @var array<string, string|AssistantMessage> */
    private array $postToolResponseRules = [];

    /** @var array<string, Throwable> */
    private array $errorRules = [];

    /** @var list<ToolInterface> */
    private array $tools = [];

    private ?MessageMapperInterface $messageMapper = null;

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        // Fake provider doesn't use system prompt, but implements interface
        return $this;
    }

    /**
     * @param list<ToolInterface> $tools
     */
    public function setTools(array $tools): AIProviderInterface
    {
        /** @var list<ToolInterface> $toolList */
        $toolList = [];
        foreach ($tools as $tool) {
            $toolList[] = $tool;
        }
        $this->tools = $toolList;

        return $this;
    }

    public function messageMapper(): MessageMapperInterface
    {
        if ($this->messageMapper === null) {
            // Use OpenAI's message mapper as a reference implementation
            $this->messageMapper = new OpenAIMessageMapper();
        }

        return $this->messageMapper;
    }

    /**
     * @param list<Message> $messages
     */
    public function chat(array $messages): Message
    {
        $lastMessage = $this->getLastUserMessage($messages);

        if ($lastMessage === null) {
            return new AssistantMessage('No user message found');
        }

        // Check for errors first
        $errorPattern = $this->findMatchingRule($lastMessage, $this->errorRules);
        if ($errorPattern !== null) {
            throw $this->errorRules[$errorPattern];
        }

        // Check for tool calls
        $toolCallPattern = $this->findMatchingRule($lastMessage, $this->toolCallRules);
        if ($toolCallPattern !== null) {
            $rule = $this->toolCallRules[$toolCallPattern];
            $tool = $this->findToolByName($rule['tool']);

            if ($tool === null) {
                return new AssistantMessage("Tool '{$rule['tool']}' not found");
            }

            $toolWithInputs = Tool::make($tool->getName(), $tool->getDescription())
                ->setInputs($rule['inputs']);

            return new ToolCallMessage(null, [$toolWithInputs]);
        }

        // Check for direct responses
        $responsePattern = $this->findMatchingRule($lastMessage, $this->responseRules);
        if ($responsePattern !== null) {
            $response = $this->responseRules[$responsePattern];

            return is_string($response) ? new AssistantMessage($response) : $response;
        }

        // Default: empty response
        return new AssistantMessage('');
    }

    /**
     * @param list<Message>|string                             $messages
     * @param callable(ToolCallMessage): ToolCallResultMessage $executeToolsCallback
     *
     * @return Generator<string|Message>
     */
    public function stream(array|string $messages, callable $executeToolsCallback): Generator
    {
        $messageArray = is_string($messages) ? [new UserMessage($messages)] : $messages;
        $lastMessage  = $this->getLastUserMessage($messageArray);

        if ($lastMessage === null) {
            yield new AssistantMessage('No user message found');

            return;
        }

        // Check for errors first
        $errorPattern = $this->findMatchingRule($lastMessage, $this->errorRules);
        if ($errorPattern !== null) {
            throw $this->errorRules[$errorPattern];
        }

        // Check for tool calls
        $toolCallPattern = $this->findMatchingRule($lastMessage, $this->toolCallRules);
        if ($toolCallPattern !== null) {
            $rule = $this->toolCallRules[$toolCallPattern];
            $tool = $this->findToolByName($rule['tool']);

            if ($tool === null) {
                yield new AssistantMessage("Tool '{$rule['tool']}' not found");

                return;
            }

            $toolWithInputs = Tool::make($tool->getName(), $tool->getDescription())
                ->setInputs($rule['inputs']);

            $toolCallMessage = new ToolCallMessage(null, [$toolWithInputs]);

            // Yield the tool call message - this will trigger real tool execution
            yield $toolCallMessage;

            // Execute the real tool via callback
            $toolResult = $executeToolsCallback($toolCallMessage);

            // Check for post-tool response rules
            $toolResultContent = $this->extractToolResultContent($toolResult);
            if ($toolResultContent !== null) {
                $postToolPattern = $this->findMatchingRule($toolResultContent, $this->postToolResponseRules);
                if ($postToolPattern !== null) {
                    $response = $this->postToolResponseRules[$postToolPattern];

                    // Yield as chunks if it's a string response
                    if (is_string($response)) {
                        $chars = mb_str_split($response);
                        foreach ($chars as $char) {
                            yield $char;
                        }
                    } else {
                        yield $response;
                    }

                    return;
                }
            }

            // Default: empty response after tool execution
            yield new AssistantMessage('');

            return;
        }

        // Check for direct responses
        $responsePattern = $this->findMatchingRule($lastMessage, $this->responseRules);
        if ($responsePattern !== null) {
            $response = $this->responseRules[$responsePattern];

            // Yield as chunks if it's a string response
            if (is_string($response)) {
                // Simulate streaming by yielding character by character
                $chars = mb_str_split($response);
                foreach ($chars as $char) {
                    yield $char;
                }
            } else {
                yield $response;
            }

            return;
        }

        // Default: empty response
        yield new AssistantMessage('');
    }

    /**
     * @param list<Message> $messages
     *
     * @phpstan-ignore-next-line - response_schema is an associative array from AIProviderInterface
     */
    public function structured(array $messages, string $class, array $response_schema): Message
    {
        $lastMessage = $this->getLastUserMessage($messages);

        if ($lastMessage === null) {
            throw new RuntimeException('No user message found for structured output');
        }

        // For fake provider, return a simple assistant message
        // In real tests, you'd seed this with specific structured data
        return new AssistantMessage('');
    }

    /**
     * @param list<Message> $messages
     */
    public function chatAsync(array $messages): PromiseInterface
    {
        // For fake provider, return a resolved promise with the chat result
        $result = $this->chat($messages);

        return new FulfilledPromise($result);
    }

    public function setClient(Client $client): AIProviderInterface
    {
        // Fake provider doesn't need HTTP client
        return $this;
    }

    public function toolPayloadMapper(): ToolPayloadMapperInterface
    {
        // Return a simple mapper - in tests this might not be used
        // We can create a minimal implementation or use OpenAI's
        return new \NeuronAI\Providers\OpenAI\ToolPayloadMapper();
    }

    /**
     * Seed a response rule that matches when message content contains the pattern.
     */
    public function seedResponse(string $messagePattern, string|AssistantMessage $response): void
    {
        $this->responseRules[$messagePattern] = $response;
    }

    /**
     * Seed a tool call rule that triggers when message content contains the pattern.
     * The real tool will be executed.
     *
     * @phpstan-ignore-next-line - toolInputs is an associative array (tool parameter map)
     */
    public function seedToolCall(string $messagePattern, string $toolName, array $toolInputs): void
    {
        /** @var array<string, mixed> $inputs */
        $inputs                               = $toolInputs;
        $this->toolCallRules[$messagePattern] = [
            'tool'   => $toolName,
            'inputs' => $inputs,
        ];
    }

    /**
     * Seed a response rule that triggers after a tool executes.
     * The pattern matches against the tool result content.
     */
    public function seedPostToolResponse(string $toolResultPattern, string|AssistantMessage $response): void
    {
        $this->postToolResponseRules[$toolResultPattern] = $response;
    }

    /**
     * Seed an error rule that throws when message content contains the pattern.
     */
    public function seedError(string $messagePattern, Throwable $error): void
    {
        $this->errorRules[$messagePattern] = $error;
    }

    /**
     * Clear all seeded rules.
     */
    public function clearRules(): void
    {
        $this->responseRules         = [];
        $this->toolCallRules         = [];
        $this->postToolResponseRules = [];
        $this->errorRules            = [];
    }

    /**
     * Extract the last user message content for pattern matching.
     *
     * @param list<Message> $messages
     */
    private function getLastUserMessage(array $messages): ?string
    {
        foreach (array_reverse($messages) as $message) {
            if ($message instanceof UserMessage) {
                $content = $message->getContent();

                return is_string($content) ? $content : null;
            }
        }

        return null;
    }

    /**
     * Check if a message matches a pattern (supports contains/exact match).
     */
    private function matchesPattern(string $message, string $pattern): bool
    {
        // Exact match
        if ($message === $pattern) {
            return true;
        }

        // Contains match
        if (str_contains($message, $pattern)) {
            return true;
        }

        return false;
    }

    /**
     * Find a matching rule key for the given message.
     *
     * @param array<string, mixed> $rules
     */
    private function findMatchingRule(string $message, array $rules): ?string
    {
        foreach ($rules as $pattern => $value) {
            if ($this->matchesPattern($message, $pattern)) {
                return $pattern;
            }
        }

        return null;
    }

    /**
     * Find a tool by name from the registered tools.
     */
    private function findToolByName(string $name): ?ToolInterface
    {
        foreach ($this->tools as $tool) {
            if ($tool->getName() === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Extract tool result content for pattern matching.
     */
    private function extractToolResultContent(ToolCallResultMessage $message): ?string
    {
        foreach ($message->getTools() as $tool) {
            if ($tool instanceof Tool) {
                return $tool->getResult();
            }
        }

        return null;
    }
}
