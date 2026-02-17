<?php

declare(strict_types=1);

namespace App\Tests\Unit\PhotoBuilder;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\HttpClientOptions;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for Gemini function-call parsing behavior.
 * Ensures upstream Gemini provider handles mixed text/function-call parts.
 */
final class GeminiProviderBehaviorTest extends TestCase
{
    public function testDetectsFunctionCallInFirstPart(): void
    {
        $responseBody = $this->buildGeminiResponse([
            ['functionCall' => ['name' => 'my_tool', 'args' => ['input' => 'hello']], 'thoughtSignature' => 'sig-abc'],
        ]);

        $provider = $this->createGemini($responseBody);
        $result   = $provider->chat([new UserMessage('test')]);

        self::assertInstanceOf(ToolCallMessage::class, $result);
        self::assertCount(1, $result->getTools());
        self::assertSame('my_tool', $result->getTools()[0]->getName());
        self::assertSame('sig-abc', $result->getMetadata('thoughtSignature'));
    }

    public function testDetectsFunctionCallAfterTextPart(): void
    {
        $responseBody = $this->buildGeminiResponse([
            ['text' => 'Let me help you with that.'],
            ['functionCall' => ['name' => 'my_tool', 'args' => ['input' => 'data']], 'thoughtSignature' => 'sig-def'],
        ]);

        $provider = $this->createGemini($responseBody);
        $result   = $provider->chat([new UserMessage('test')]);

        self::assertInstanceOf(ToolCallMessage::class, $result);
        self::assertCount(1, $result->getTools());
        self::assertSame('my_tool', $result->getTools()[0]->getName());
    }

    public function testDetectsMultipleFunctionCallsAfterTextPart(): void
    {
        $responseBody = $this->buildGeminiResponse([
            ['text' => 'I will generate the prompts now.'],
            ['functionCall' => ['name' => 'tool_a', 'args' => ['x' => '1']], 'thoughtSignature' => 'sig-multi'],
            ['functionCall' => ['name' => 'tool_b', 'args' => ['x' => '2']]],
        ]);

        $provider = $this->createGeminiWithTools(
            $responseBody,
            [
                $this->makeTool('tool_a'),
                $this->makeTool('tool_b'),
            ],
        );
        $result = $provider->chat([new UserMessage('test')]);

        self::assertInstanceOf(ToolCallMessage::class, $result);
        self::assertCount(2, $result->getTools());
        self::assertSame('tool_a', $result->getTools()[0]->getName());
        self::assertSame('tool_b', $result->getTools()[1]->getName());
    }

    public function testReturnsAssistantMessageWhenNoFunctionCall(): void
    {
        $responseBody = $this->buildGeminiResponse([
            ['text' => 'Here is a plain text response.'],
        ]);

        $provider = $this->createGemini($responseBody);
        $result   = $provider->chat([new UserMessage('test')]);

        self::assertInstanceOf(AssistantMessage::class, $result);
        self::assertSame('Here is a plain text response.', $result->getContent());
    }

    public function testAttachesUsageMetadata(): void
    {
        $responseBody = json_encode([
            'candidates' => [
                [
                    'content'      => ['parts' => [['text' => 'response']]],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount'     => 42,
                'candidatesTokenCount' => 13,
            ],
        ], JSON_THROW_ON_ERROR);

        $provider = $this->createGemini($responseBody);
        $result   = $provider->chat([new UserMessage('test')]);

        $usage = $result->getUsage();
        self::assertNotNull($usage);
        self::assertSame(42, $usage->inputTokens);
        self::assertSame(13, $usage->outputTokens);
    }

    /**
     * @param array<int, array<string, mixed>> $parts
     */
    private function buildGeminiResponse(array $parts): string
    {
        return json_encode([
            'candidates' => [
                [
                    'content'      => ['parts' => $parts],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount'     => 100,
                'candidatesTokenCount' => 50,
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function createGemini(string $responseBody): Gemini
    {
        return $this->createGeminiWithTools(
            $responseBody,
            [$this->makeTool('my_tool')],
        );
    }

    /**
     * @param list<Tool> $tools
     */
    private function createGeminiWithTools(string $responseBody, array $tools): Gemini
    {
        $mock         = new MockHandler([new Response(200, [], $responseBody)]);
        $handlerStack = HandlerStack::create($mock);
        $httpOptions  = new HttpClientOptions(null, null, null, $handlerStack);

        $provider = new Gemini(
            'fake-api-key',
            'gemini-3-pro-preview',
            [],
            $httpOptions,
        );
        $provider->setTools($tools);

        return $provider;
    }

    private function makeTool(string $name): Tool
    {
        /** @var Tool $tool */
        $tool = Tool::make($name, "A test tool called {$name}.")
            ->addProperty(
                new ToolProperty('input', PropertyType::STRING, 'Test input', true),
            )
            ->setCallable(static fn (string $input): string => "result: {$input}");

        return $tool;
    }
}
