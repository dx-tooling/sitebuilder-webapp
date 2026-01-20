<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\LlmContentEditor\Facade\Dto\EditStreamChunkDto;
use App\LlmContentEditor\Facade\LlmContentEditorFacadeInterface;
use App\LlmContentEditor\Infrastructure\Provider\AIProviderFactoryInterface;
use App\LlmContentEditor\Infrastructure\Provider\Dto\ErrorRuleDto;
use App\LlmContentEditor\Infrastructure\Provider\Dto\FakeProviderSeedingRulesDto;
use App\LlmContentEditor\Infrastructure\Provider\Dto\PostToolResponseRuleDto;
use App\LlmContentEditor\Infrastructure\Provider\Dto\ResponseRuleDto;
use App\LlmContentEditor\Infrastructure\Provider\Dto\ToolCallRuleDto;
use App\LlmContentEditor\Infrastructure\Provider\Dto\ToolInputsDto;
use App\LlmContentEditor\Infrastructure\Provider\FakeAIProviderFactory;
use App\Tests\TestHelpers\FakeAIProviderSeeder;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class LlmContentEditorFacadeWithFakeProviderTest extends KernelTestCase
{
    private string $tempWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->tempWorkspace = sys_get_temp_dir() . '/llm_test_' . uniqid();
        mkdir($this->tempWorkspace, 0755, true);

        // Clear any existing rules from the fake provider factory
        $container = static::getContainer();
        /** @var AIProviderFactoryInterface $providerFactory */
        $providerFactory = $container->get(AIProviderFactoryInterface::class);
        if ($providerFactory instanceof FakeAIProviderFactory) {
            FakeAIProviderSeeder::clear($providerFactory);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempWorkspace);
        parent::tearDown();
    }

    public function testSimpleResponse(): void
    {
        $container = static::getContainer();

        /** @var AIProviderFactoryInterface $providerFactory */
        $providerFactory = $container->get(AIProviderFactoryInterface::class);
        assert($providerFactory instanceof FakeAIProviderFactory);

        /** @var LlmContentEditorFacadeInterface $facade */
        $facade = $container->get(LlmContentEditorFacadeInterface::class);

        // Seed a simple response
        $rules = new FakeProviderSeedingRulesDto(
            [
                new ResponseRuleDto('hello', 'Hello! How can I help you?'),
            ]
        );
        FakeAIProviderSeeder::seed($providerFactory, $rules);

        // Call the facade
        $instruction = 'hello';
        $chunks      = [];
        foreach ($facade->streamEditWithHistory($this->tempWorkspace, $instruction) as $chunk) {
            $chunks[] = $chunk;
        }

        // Verify chunks
        $textChunks    = array_filter($chunks, fn (EditStreamChunkDto $c) => $c->chunkType === 'text');
        $doneChunk     = null;
        $messageChunks = [];

        foreach ($chunks as $chunk) {
            if ($chunk->chunkType === 'done') {
                $doneChunk = $chunk;
            } elseif ($chunk->chunkType === 'message') {
                $messageChunks[] = $chunk;
            }
        }

        // Assert text chunks contain the response
        self::assertNotEmpty($textChunks, 'Should have text chunks');
        $textContent = '';
        foreach ($textChunks as $chunk) {
            self::assertNotNull($chunk->content);
            $textContent .= $chunk->content;
        }
        self::assertStringContainsString('Hello! How can I help you?', $textContent);

        // Assert done chunk
        self::assertNotNull($doneChunk, 'Should have done chunk');
        self::assertTrue($doneChunk->success, 'Should be successful');
        self::assertNull($doneChunk->errorMessage, 'Should not have error message');

        // Assert messages were persisted
        self::assertNotEmpty($messageChunks, 'Should have message chunks');
        $userMessageFound      = false;
        $assistantMessageFound = false;

        foreach ($messageChunks as $chunk) {
            $message = $chunk->message;
            self::assertNotNull($message);
            if ($message->role === 'user') {
                $userMessageFound = true;
                self::assertStringContainsString($instruction, $message->contentJson);
            } elseif ($message->role === 'assistant') {
                $assistantMessageFound = true;
                self::assertStringContainsString('Hello! How can I help you?', $message->contentJson);
            }
        }

        self::assertTrue($userMessageFound, 'Should have user message');
        self::assertTrue($assistantMessageFound, 'Should have assistant message');
    }

    public function testToolCallExecution(): void
    {
        $container = static::getContainer();

        /** @var AIProviderFactoryInterface $providerFactory */
        $providerFactory = $container->get(AIProviderFactoryInterface::class);
        assert($providerFactory instanceof FakeAIProviderFactory);

        /** @var LlmContentEditorFacadeInterface $facade */
        $facade = $container->get(LlmContentEditorFacadeInterface::class);

        // Create test files in workspace
        file_put_contents($this->tempWorkspace . '/test.txt', 'Test content');
        file_put_contents($this->tempWorkspace . '/package.json', '{"name": "test"}');

        // Seed a tool call rule - test with a tool we know exists (run_quality_checks)
        // This tool is defined in ContentEditorAgent, so it should be available
        // The prompt format is: "The working folder is: {path}\n\nPlease perform the following task: {instruction}"
        // Match against the instruction part which will be found in the full prompt
        $instruction = 'run quality checks now';
        // Use a pattern that will match the full prompt (str_contains will find it)
        $pattern = 'Please perform the following task: ' . $instruction;

        $rules = new FakeProviderSeedingRulesDto(
            [],
            [
                new ToolCallRuleDto(
                    $pattern,
                    'run_quality_checks',
                    ToolInputsDto::fromArray(['path' => $this->tempWorkspace])
                ),
            ]
        );
        FakeAIProviderSeeder::seed($providerFactory, $rules);

        // Call the facade
        $chunks = [];
        foreach ($facade->streamEditWithHistory($this->tempWorkspace, $instruction) as $chunk) {
            $chunks[] = $chunk;
        }

        // Verify chunks
        $eventChunks = array_filter($chunks, fn (EditStreamChunkDto $c) => $c->chunkType === 'event');
        $doneChunk   = null;
        $textChunks  = array_filter($chunks, fn (EditStreamChunkDto $c) => $c->chunkType === 'text');

        foreach ($chunks as $chunk) {
            if ($chunk->chunkType === 'done') {
                $doneChunk = $chunk;
            }
        }

        // Debug: If no events, check what we got
        if (empty($eventChunks)) {
            $chunkTypes  = array_map(fn (EditStreamChunkDto $c) => $c->chunkType, $chunks);
            $textContent = '';
            foreach ($textChunks as $chunk) {
                if ($chunk->content !== null) {
                    $textContent .= $chunk->content;
                }
            }
            $messageContent = '';
            foreach ($chunks as $chunk) {
                if ($chunk->chunkType === 'message' && $chunk->message !== null) {
                    $messageContent .= $chunk->message->role . ': ' . $chunk->message->contentJson . "\n";
                }
            }
            // Check if we got an assistant message with tool not found error
            $assistantMessage = null;
            foreach ($chunks as $chunk) {
                if ($chunk->chunkType === 'message' && $chunk->message !== null && $chunk->message->role === 'assistant') {
                    $assistantMessage = $chunk->message->contentJson;
                }
            }

            // Also check text chunks for any error messages
            $allText = '';
            foreach ($chunks as $chunk) {
                if ($chunk->chunkType === 'text' && $chunk->content !== null) {
                    $allText .= $chunk->content;
                }
            }

            self::fail(
                'No event chunks found. Chunk types: ' . implode(', ', array_unique($chunkTypes)) .
                '. Text content: "' . $textContent . '". All text: "' . $allText . '". Messages: ' . $messageContent .
                '. Assistant message: ' . ($assistantMessage ?? 'none') . '. Total chunks: ' . count($chunks)
            );
        }

        // Assert tool call events (we already fail above if eventChunks is empty)
        $toolCallingFound = false;
        $toolCalledFound  = false;

        foreach ($eventChunks as $chunk) {
            $event = $chunk->event;
            self::assertNotNull($event);
            if ($event->kind === 'tool_calling' && $event->toolName === 'run_quality_checks') {
                $toolCallingFound = true;
                self::assertNotNull($event->toolInputs);
            } elseif ($event->kind === 'tool_called' && $event->toolName === 'run_quality_checks') {
                $toolCalledFound = true;
                // Verify tool actually executed (toolResult is non-null string after tool execution)
                self::assertNotNull($event->toolResult);
            }
        }

        self::assertTrue($toolCallingFound, 'Should have tool_calling event');
        self::assertTrue($toolCalledFound, 'Should have tool_called event');

        // Assert done chunk
        self::assertNotNull($doneChunk, 'Should have done chunk');
        self::assertNull($doneChunk->errorMessage, 'Should not have error: ' . ($doneChunk->errorMessage ?? 'none'));
        self::assertTrue($doneChunk->success, 'Should be successful');
    }

    public function testPostToolResponse(): void
    {
        $container = static::getContainer();

        /** @var AIProviderFactoryInterface $providerFactory */
        $providerFactory = $container->get(AIProviderFactoryInterface::class);
        assert($providerFactory instanceof FakeAIProviderFactory);

        /** @var LlmContentEditorFacadeInterface $facade */
        $facade = $container->get(LlmContentEditorFacadeInterface::class);

        // Create test files in workspace
        file_put_contents($this->tempWorkspace . '/package.json', '{"name": "test"}');

        $instruction = 'check quality and report';

        // Seed a tool call that triggers run_quality_checks, then a post-tool response
        $rules = new FakeProviderSeedingRulesDto(
            [],
            [
                new ToolCallRuleDto(
                    $instruction,
                    'run_quality_checks',
                    ToolInputsDto::fromArray(['path' => $this->tempWorkspace])
                ),
            ],
            [
                // Match any tool result (since we don't know exact output)
                new PostToolResponseRuleDto('', 'Quality checks completed successfully!'),
            ]
        );
        FakeAIProviderSeeder::seed($providerFactory, $rules);

        // Call the facade
        $chunks = [];
        foreach ($facade->streamEditWithHistory($this->tempWorkspace, $instruction) as $chunk) {
            $chunks[] = $chunk;
        }

        // Verify we got tool call events
        $eventChunks = array_filter($chunks, fn (EditStreamChunkDto $c) => $c->chunkType === 'event');
        self::assertNotEmpty($eventChunks, 'Should have event chunks');

        // Verify we got post-tool text response
        $textChunks  = array_filter($chunks, fn (EditStreamChunkDto $c) => $c->chunkType === 'text');
        $textContent = '';
        foreach ($textChunks as $chunk) {
            if ($chunk->content !== null) {
                $textContent .= $chunk->content;
            }
        }
        self::assertStringContainsString('Quality checks completed successfully!', $textContent);

        // Verify done chunk
        $doneChunk = null;
        foreach ($chunks as $chunk) {
            if ($chunk->chunkType === 'done') {
                $doneChunk = $chunk;
            }
        }
        self::assertNotNull($doneChunk, 'Should have done chunk');
        self::assertTrue($doneChunk->success, 'Should be successful');
    }

    public function testErrorHandling(): void
    {
        $container = static::getContainer();

        /** @var AIProviderFactoryInterface $providerFactory */
        $providerFactory = $container->get(AIProviderFactoryInterface::class);
        assert($providerFactory instanceof FakeAIProviderFactory);

        /** @var LlmContentEditorFacadeInterface $facade */
        $facade = $container->get(LlmContentEditorFacadeInterface::class);

        $instruction = 'trigger error';

        // Seed an error rule
        $rules = new FakeProviderSeedingRulesDto(
            [],
            [],
            [],
            [
                new ErrorRuleDto($instruction, new RuntimeException('Test error from fake provider')),
            ]
        );
        FakeAIProviderSeeder::seed($providerFactory, $rules);

        // Call the facade
        $chunks = [];
        foreach ($facade->streamEditWithHistory($this->tempWorkspace, $instruction) as $chunk) {
            $chunks[] = $chunk;
        }

        // Verify we got a done chunk with error
        $doneChunk = null;
        foreach ($chunks as $chunk) {
            if ($chunk->chunkType === 'done') {
                $doneChunk = $chunk;
            }
        }

        self::assertNotNull($doneChunk, 'Should have done chunk');
        self::assertFalse($doneChunk->success, 'Should not be successful');
        self::assertNotNull($doneChunk->errorMessage, 'Should have error message');
        self::assertStringContainsString('Test error from fake provider', $doneChunk->errorMessage);
    }

    public function testMultiTurnConversation(): void
    {
        $container = static::getContainer();

        /** @var AIProviderFactoryInterface $providerFactory */
        $providerFactory = $container->get(AIProviderFactoryInterface::class);
        assert($providerFactory instanceof FakeAIProviderFactory);

        /** @var LlmContentEditorFacadeInterface $facade */
        $facade = $container->get(LlmContentEditorFacadeInterface::class);

        // First turn: simple response
        $firstInstruction = 'hello';
        $rules1           = new FakeProviderSeedingRulesDto(
            [
                new ResponseRuleDto('hello', 'Hello! How can I help?'),
            ]
        );
        FakeAIProviderSeeder::seed($providerFactory, $rules1);

        $firstChunks = [];
        foreach ($facade->streamEditWithHistory($this->tempWorkspace, $firstInstruction) as $chunk) {
            $firstChunks[] = $chunk;
        }

        // Extract messages from first turn
        $firstMessages = [];
        foreach ($firstChunks as $chunk) {
            if ($chunk->chunkType === 'message' && $chunk->message !== null) {
                $firstMessages[] = $chunk->message;
            }
        }

        self::assertNotEmpty($firstMessages, 'Should have messages from first turn');

        // Second turn: follow-up with history
        $secondInstruction = 'what can you do?';
        $rules2            = new FakeProviderSeedingRulesDto(
            [
                new ResponseRuleDto('what can you do?', 'I can help you edit files and run commands.'),
            ]
        );
        FakeAIProviderSeeder::seed($providerFactory, $rules2);

        $secondChunks = [];
        foreach ($facade->streamEditWithHistory($this->tempWorkspace, $secondInstruction, $firstMessages) as $chunk) {
            $secondChunks[] = $chunk;
        }

        // Verify second turn has response
        $secondTextChunks  = array_filter($secondChunks, fn (EditStreamChunkDto $c) => $c->chunkType === 'text');
        $secondTextContent = '';
        foreach ($secondTextChunks as $chunk) {
            if ($chunk->content !== null) {
                $secondTextContent .= $chunk->content;
            }
        }
        self::assertStringContainsString('I can help you edit files and run commands.', $secondTextContent);

        // Verify done chunk
        $doneChunk = null;
        foreach ($secondChunks as $chunk) {
            if ($chunk->chunkType === 'done') {
                $doneChunk = $chunk;
            }
        }
        self::assertNotNull($doneChunk, 'Should have done chunk');
        self::assertTrue($doneChunk->success, 'Should be successful');
    }

    public function testDifferentTools(): void
    {
        $container = static::getContainer();

        /** @var AIProviderFactoryInterface $providerFactory */
        $providerFactory = $container->get(AIProviderFactoryInterface::class);
        assert($providerFactory instanceof FakeAIProviderFactory);

        /** @var LlmContentEditorFacadeInterface $facade */
        $facade = $container->get(LlmContentEditorFacadeInterface::class);

        // Create test files in workspace
        file_put_contents($this->tempWorkspace . '/package.json', '{"name": "test", "scripts": {"test": "echo test", "build": "echo build"}}');

        // Test run_tests tool
        $testInstruction = 'run tests now';
        $rules           = new FakeProviderSeedingRulesDto(
            [],
            [
                new ToolCallRuleDto(
                    $testInstruction,
                    'run_tests',
                    ToolInputsDto::fromArray(['path' => $this->tempWorkspace])
                ),
            ]
        );
        FakeAIProviderSeeder::seed($providerFactory, $rules);

        $chunks = [];
        foreach ($facade->streamEditWithHistory($this->tempWorkspace, $testInstruction) as $chunk) {
            $chunks[] = $chunk;
        }

        $eventChunks      = array_filter($chunks, fn (EditStreamChunkDto $c) => $c->chunkType === 'event');
        $toolCallingFound = false;
        foreach ($eventChunks as $chunk) {
            $event = $chunk->event;
            if ($event !== null && $event->kind === 'tool_calling' && $event->toolName === 'run_tests') {
                $toolCallingFound = true;
            }
        }
        self::assertTrue($toolCallingFound, 'Should have called run_tests tool');

        // Test run_build tool
        FakeAIProviderSeeder::clear($providerFactory);
        $buildInstruction = 'build the project';
        $buildRules       = new FakeProviderSeedingRulesDto(
            [],
            [
                new ToolCallRuleDto(
                    $buildInstruction,
                    'run_build',
                    ToolInputsDto::fromArray(['path' => $this->tempWorkspace])
                ),
            ]
        );
        FakeAIProviderSeeder::seed($providerFactory, $buildRules);

        $buildChunks = [];
        foreach ($facade->streamEditWithHistory($this->tempWorkspace, $buildInstruction) as $chunk) {
            $buildChunks[] = $chunk;
        }

        $buildEventChunks      = array_filter($buildChunks, fn (EditStreamChunkDto $c) => $c->chunkType === 'event');
        $buildToolCallingFound = false;
        foreach ($buildEventChunks as $chunk) {
            $event = $chunk->event;
            if ($event !== null && $event->kind === 'tool_calling' && $event->toolName === 'run_build') {
                $buildToolCallingFound = true;
            }
        }
        self::assertTrue($buildToolCallingFound, 'Should have called run_build tool');
    }

    public function testStreamingBehavior(): void
    {
        $container = static::getContainer();

        /** @var AIProviderFactoryInterface $providerFactory */
        $providerFactory = $container->get(AIProviderFactoryInterface::class);
        assert($providerFactory instanceof FakeAIProviderFactory);

        /** @var LlmContentEditorFacadeInterface $facade */
        $facade = $container->get(LlmContentEditorFacadeInterface::class);

        // Seed a longer response to verify streaming
        $longResponse = 'This is a longer response that should be streamed character by character to simulate real streaming behavior.';
        $rules        = new FakeProviderSeedingRulesDto(
            [
                new ResponseRuleDto('stream test', $longResponse),
            ]
        );
        FakeAIProviderSeeder::seed($providerFactory, $rules);

        $instruction = 'stream test';
        $chunks      = [];
        foreach ($facade->streamEditWithHistory($this->tempWorkspace, $instruction) as $chunk) {
            $chunks[] = $chunk;
        }

        // Verify we got multiple text chunks (streaming)
        $textChunks = array_filter($chunks, fn (EditStreamChunkDto $c) => $c->chunkType === 'text');
        self::assertGreaterThan(1, count($textChunks), 'Should have multiple text chunks for streaming');

        // Verify all chunks together form the complete response
        $textContent = '';
        foreach ($textChunks as $chunk) {
            if ($chunk->content !== null) {
                $textContent .= $chunk->content;
            }
        }
        self::assertEquals($longResponse, $textContent, 'Streamed chunks should form complete response');
    }

    public function testToolNotFound(): void
    {
        $container = static::getContainer();

        /** @var AIProviderFactoryInterface $providerFactory */
        $providerFactory = $container->get(AIProviderFactoryInterface::class);
        assert($providerFactory instanceof FakeAIProviderFactory);

        /** @var LlmContentEditorFacadeInterface $facade */
        $facade = $container->get(LlmContentEditorFacadeInterface::class);

        $instruction = 'call nonexistent tool';

        // Seed a tool call for a tool that doesn't exist
        $rules = new FakeProviderSeedingRulesDto(
            [],
            [
                new ToolCallRuleDto(
                    $instruction,
                    'nonexistent_tool',
                    ToolInputsDto::fromArray(['path' => $this->tempWorkspace])
                ),
            ]
        );
        FakeAIProviderSeeder::seed($providerFactory, $rules);

        $chunks = [];
        foreach ($facade->streamEditWithHistory($this->tempWorkspace, $instruction) as $chunk) {
            $chunks[] = $chunk;
        }

        // Verify we got an error message about tool not found
        $textChunks  = array_filter($chunks, fn (EditStreamChunkDto $c) => $c->chunkType === 'text');
        $textContent = '';
        foreach ($textChunks as $chunk) {
            if ($chunk->content !== null) {
                $textContent .= $chunk->content;
            }
        }
        self::assertStringContainsString('not found', $textContent, 'Should indicate tool not found');

        // Verify done chunk (should still succeed, just with error message)
        $doneChunk = null;
        foreach ($chunks as $chunk) {
            if ($chunk->chunkType === 'done') {
                $doneChunk = $chunk;
            }
        }
        self::assertNotNull($doneChunk, 'Should have done chunk');
    }

    public function testEmptyResponse(): void
    {
        $container = static::getContainer();

        /** @var AIProviderFactoryInterface $providerFactory */
        $providerFactory = $container->get(AIProviderFactoryInterface::class);
        assert($providerFactory instanceof FakeAIProviderFactory);

        /** @var LlmContentEditorFacadeInterface $facade */
        $facade = $container->get(LlmContentEditorFacadeInterface::class);

        $instruction = 'no response rule';

        // Don't seed any rules - should get empty response
        FakeAIProviderSeeder::clear($providerFactory);

        $chunks = [];
        foreach ($facade->streamEditWithHistory($this->tempWorkspace, $instruction) as $chunk) {
            $chunks[] = $chunk;
        }

        // Verify we got a done chunk (even with empty response)
        $doneChunk = null;
        foreach ($chunks as $chunk) {
            if ($chunk->chunkType === 'done') {
                $doneChunk = $chunk;
            }
        }
        self::assertNotNull($doneChunk, 'Should have done chunk');
        self::assertTrue($doneChunk->success, 'Should be successful even with empty response');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
