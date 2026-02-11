<?php

declare(strict_types=1);

namespace Tests\Unit\PhotoBuilder;

use App\PhotoBuilder\Infrastructure\Adapter\PatchedGemini;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\HttpClientOptions;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

/**
 * Tests that PatchedGemini correctly detects function calls in any position
 * within the response parts array, not just parts[0].
 */
final class PatchedGeminiTest extends TestCase
{
    public function testDetectsFunctionCallInFirstPart(): void
    {
        $responseBody = $this->buildGeminiResponse([
            ['functionCall' => ['name' => 'my_tool', 'args' => ['input' => 'hello']], 'thoughtSignature' => 'sig-abc'],
        ]);

        $provider = $this->createPatchedGemini($responseBody);

        $result = $provider->chat([new UserMessage('test')]);

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

        $provider = $this->createPatchedGemini($responseBody);

        $result = $provider->chat([new UserMessage('test')]);

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

        $provider = $this->createPatchedGeminiWithTools(
            $responseBody,
            [
                $this->makeTool('tool_a'),
                $this->makeTool('tool_b'),
            ],
        );

        $result = $provider->chat([new UserMessage('test')]);

        self::assertInstanceOf(ToolCallMessage::class, $result);
        self::assertCount(2, $result->getTools());
    }

    public function testReturnsAssistantMessageWhenNoFunctionCall(): void
    {
        $responseBody = $this->buildGeminiResponse([
            ['text' => 'Here is a plain text response.'],
        ]);

        $provider = $this->createPatchedGemini($responseBody);

        $result = $provider->chat([new UserMessage('test')]);

        self::assertInstanceOf(AssistantMessage::class, $result);
        self::assertSame('Here is a plain text response.', $result->getContent());
    }

    public function testReturnsAssistantMessageForMultipleTextParts(): void
    {
        $responseBody = $this->buildGeminiResponse([
            ['text' => 'First part of the answer.'],
            ['text' => 'Second part of the answer.'],
        ]);

        $provider = $this->createPatchedGemini($responseBody);

        $result = $provider->chat([new UserMessage('test')]);

        self::assertInstanceOf(AssistantMessage::class, $result);
        self::assertSame('First part of the answer.', $result->getContent());
    }

    public function testAttachesUsageMetadata(): void
    {
        $responseBody = json_encode([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'response']],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount'     => 42,
                'candidatesTokenCount' => 13,
            ],
        ], JSON_THROW_ON_ERROR);

        $provider = $this->createPatchedGemini($responseBody);

        $result = $provider->chat([new UserMessage('test')]);

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
                    'content' => [
                        'parts' => $parts,
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount'     => 100,
                'candidatesTokenCount' => 50,
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function createPatchedGemini(string $responseBody): PatchedGemini
    {
        return $this->createPatchedGeminiWithTools(
            $responseBody,
            [$this->makeTool('my_tool')],
        );
    }

    /**
     * @param list<Tool> $tools
     */
    private function createPatchedGeminiWithTools(string $responseBody, array $tools): PatchedGemini
    {
        $mock         = new MockHandler([new Response(200, [], $responseBody)]);
        $handlerStack = HandlerStack::create($mock);
        $httpOptions  = new HttpClientOptions(null, null, null, $handlerStack);

        $provider = new PatchedGemini(
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
