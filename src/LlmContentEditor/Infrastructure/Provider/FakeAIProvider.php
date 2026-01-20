<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\Provider;

use App\LlmContentEditor\Infrastructure\Provider\Dto\ToolInputsDto;
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
     * @param list<Message>|string                                 $messages
     * @param callable(ToolCallMessage): Generator<string|Message> $executeToolsCallback
     *
     * @return Generator<string|Message>
     */
    public function stream(array|string $messages, callable $executeToolsCallback): Generator
    {
        $messageArray = is_string($messages) ? [new UserMessage($messages)] : $messages;
        $lastMessage  = $this->getLastUserMessage($messageArray);

        if ($lastMessage === null) {
            // No user message - just return empty (yield nothing)
            return;
        }

        // Check if this is a post-tool call (recursive stream after tool execution).
        // In this case, we should respond with text, not trigger another tool call.
        $isPostToolCall = $this->hasToolCallResult($messageArray);

        // Check for errors first
        $errorPattern = $this->findMatchingRule($lastMessage, $this->errorRules);
        if ($errorPattern !== null) {
            throw $this->errorRules[$errorPattern];
        }

        // Check for tool calls (only if not already in post-tool state)
        if (!$isPostToolCall) {
            $toolCallPattern = $this->findMatchingRule($lastMessage, $this->toolCallRules);
            if ($toolCallPattern !== null) {
                $rule = $this->toolCallRules[$toolCallPattern];
                $tool = $this->findToolByName($rule['tool']);

                if ($tool === null) {
                    // Tool not found - yield error message as string chunks
                    $errorMsg = "Tool '{$rule['tool']}' not found. Available tools: " . implode(', ', array_map(fn (ToolInterface $t) => $t->getName(), $this->tools));
                    foreach (mb_str_split($errorMsg) as $char) {
                        yield $char;
                    }

                    return;
                }

                // Use the actual tool instance (which has the callable) and set inputs on it
                $tool->setInputs($rule['inputs']);

                $toolCallMessage = new ToolCallMessage(null, [$tool]);

                // The callback is a Generator that handles tool execution and recursive streaming.
                // We yield from it to delegate control, just like the OpenAI provider does.
                yield from $executeToolsCallback($toolCallMessage);

                return;
            }
        }

        // Check for post-tool response rules (matches against tool result content)
        if ($isPostToolCall) {
            $toolResult = $this->getLastToolResult($messageArray);
            if ($toolResult !== null) {
                $postToolPattern = $this->findMatchingRule($toolResult, $this->postToolResponseRules);
                if ($postToolPattern !== null) {
                    $response = $this->postToolResponseRules[$postToolPattern];
                    if (is_string($response)) {
                        foreach (mb_str_split($response) as $char) {
                            yield $char;
                        }
                    }

                    return;
                }
            }

            // Default post-tool response: empty (tool execution is complete)
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
            }

            return;
        }

        // Default: empty response (yield nothing)
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
     */
    public function seedToolCall(string $messagePattern, string $toolName, ToolInputsDto $toolInputs): void
    {
        $this->toolCallRules[$messagePattern] = [
            'tool'   => $toolName,
            'inputs' => $toolInputs->toArray(),
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
     * Skips ToolCallResultMessage which extends UserMessage but has different content.
     *
     * @param list<Message> $messages
     */
    private function getLastUserMessage(array $messages): ?string
    {
        foreach (array_reverse($messages) as $message) {
            // Skip ToolCallResultMessage - it extends UserMessage but doesn't contain user text
            if ($message instanceof ToolCallResultMessage) {
                continue;
            }

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
     * Check if the message array contains a ToolCallResultMessage (post-tool state).
     *
     * @param list<Message> $messages
     */
    private function hasToolCallResult(array $messages): bool
    {
        foreach ($messages as $message) {
            if ($message instanceof ToolCallResultMessage) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the content of the last tool result for pattern matching.
     *
     * @param list<Message> $messages
     */
    private function getLastToolResult(array $messages): ?string
    {
        foreach (array_reverse($messages) as $message) {
            if ($message instanceof ToolCallResultMessage) {
                foreach ($message->getTools() as $tool) {
                    if ($tool instanceof Tool) {
                        return $tool->getResult();
                    }
                }
            }
        }

        return null;
    }
}
